<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\OrderFinalizer;
use App\Service\PaymentOutcome;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe's server-to-server notification endpoint — the reliable source of truth
 * for order finalization, independent of whether the customer's browser ever
 * returns to /checkout/success.
 *
 * Deliberately NOT a #[Route] attribute controller: the route is declared in
 * config/routes.yaml so it escapes the global /{_locale} prefix and answers at a
 * bare POST /stripe/webhook (where Stripe sends). Authentication is by signature,
 * not by firewall, so the path is PUBLIC_ACCESS in security.yaml.
 *
 * Idempotency lives in OrderFinalizer (one pending → paid transition per order),
 * so a duplicated event, or this webhook racing the browser return, never
 * produces a second order or a second confirmation email.
 */
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly LoggerInterface $paymentLogger,
        private readonly string $webhookSecret,
    ) {
    }

    public function handle(Request $request): Response
    {
        // The signature is computed over the exact bytes Stripe sent. Re-encoding
        // a parsed body would change them and break verification, so we read the
        // raw payload and never json_decode it ourselves.
        $payload   = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            $this->paymentLogger->warning('payment.webhook.unsigned', [
                'provider' => 'stripe',
                'reason'   => 'missing Stripe-Signature header',
            ]);

            return new Response('Missing signature', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\UnexpectedValueException $e) {
            $this->paymentLogger->warning('payment.webhook.invalid_payload', [
                'provider' => 'stripe',
                'error'    => $e->getMessage(),
            ]);

            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        } catch (SignatureVerificationException $e) {
            $this->paymentLogger->warning('payment.webhook.bad_signature', [
                'provider' => 'stripe',
                'error'    => $e->getMessage(),
            ]);

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->onPaymentIntentSucceeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $pi = $event->data->object;
                $this->paymentLogger->warning('payment.intent.failed', [
                    'provider'       => 'stripe',
                    'payment_intent' => $pi->id ?? null,
                    'last_error'     => $pi->last_payment_error->message ?? null,
                ]);
                break;

            // Any other event type is acknowledged so Stripe stops retrying it.
        }

        return new Response('', Response::HTTP_OK);
    }

    /**
     * @param \Stripe\PaymentIntent $pi
     */
    private function onPaymentIntentSucceeded($pi): void
    {
        $order = $this->orders->findOneByStripePaymentIntentId($pi->id);

        // Fallback to the order id we wrote into the PaymentIntent metadata at
        // update-payment, in case the id column was not set for some reason.
        if (!$order) {
            $metaOrderId = $pi->metadata->order_id ?? null;
            if ($metaOrderId) {
                $order = $this->orders->find((int) $metaOrderId);
            }
        }

        if (!$order) {
            // A succeeded payment we cannot tie to an order: the customer has been
            // charged with nothing to show for it on our side. Loud, and left for
            // the purge/reconciliation command to recover if an order appears.
            $this->paymentLogger->error('payment.webhook.order_not_found', [
                'provider'       => 'stripe',
                'payment_intent' => $pi->id ?? null,
                'action'         => 'charged customer has no matching order; investigate',
            ]);

            return;
        }

        $outcome = new PaymentOutcome(
            provider: 'stripe',
            providerConfirmed: ($pi->status ?? null) === 'succeeded',
            paidCurrency: $pi->currency !== null ? strtolower((string) $pi->currency) : null,
            paidMinor: isset($pi->amount_received) ? (int) $pi->amount_received : null,
            reference: $pi->id ?? null,
            // The event object does not carry expanded card details; leave brand
            // and last4 to whatever the browser return captured. 'card' is the
            // right default for PaymentElement when nothing else is known.
            paymentMethod: ($pi->payment_method_types[0] ?? null) ?: 'card',
        );

        $this->orderFinalizer->finalizePaid($order, $outcome);
    }
}
