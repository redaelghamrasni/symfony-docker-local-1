<?php

namespace App\Service;

use App\Entity\Order;
use App\Message\ReindexEntityMessage;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address as EmailAddress;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The single place a `pending` order becomes `paid`.
 *
 * The Stripe webhook, the browser return to /checkout/success and the PayPal
 * capture all converge here, so the transition — with its payment verification,
 * confirmation email, search reindex and business log — exists once rather than
 * three times. The transition is idempotent: the first caller to reach a given
 * order performs it, and every later caller (a duplicated webhook, or the
 * browser return racing the webhook) is a no-op.
 *
 * The order must already be persisted as `pending` (pre-persisted at
 * checkout/update-payment); this service never creates an order.
 */
class OrderFinalizer
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
        private readonly CurrencyService $currencyService,
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $checkoutLogger,
        private readonly LoggerInterface $paymentLogger,
    ) {
    }

    /**
     * Idempotently transitions the order to `paid`, verifying it against what the
     * provider reported and, when this call is the one that performs the
     * transition, sending the confirmation email and reindexing.
     *
     * @return bool true if THIS call performed the transition, false if the order
     *              was already finalized by another caller (nothing done)
     */
    public function finalizePaid(Order $order, PaymentOutcome $outcome): bool
    {
        $orderId = $order->getId();
        if ($orderId === null) {
            throw new \LogicException('OrderFinalizer requires a persisted order; pre-persist it as pending first.');
        }

        // Hard, DB-level idempotency: exactly one caller flips pending → paid.
        if (!$this->orders->markPaidIfPending($orderId)) {
            $this->paymentLogger->info('payment.finalize.skipped', [
                'order_id' => $orderId,
                'provider' => $outcome->provider,
                'reason'   => 'already finalized (duplicate event or browser/webhook race)',
            ]);

            return false;
        }

        // The conditional UPDATE was a bulk DQL statement; resync the managed
        // entity so it reflects status = paid before we set the rest.
        $this->em->refresh($order);

        if ($outcome->paymentMethod !== null) {
            $order->setPaymentMethod($outcome->paymentMethod);
        }
        if ($outcome->paymentBrand !== null) {
            $order->setPaymentBrand($outcome->paymentBrand);
        }
        if ($outcome->paymentLast4 !== null) {
            $order->setPaymentLast4($outcome->paymentLast4);
        }

        $this->verify($order, $outcome);

        $this->em->flush();

        $this->messageBus->dispatch(new ReindexEntityMessage('order', $orderId));
        $this->sendConfirmationEmail($order);

        // The business event, emitted once per order on the path that actually
        // finalized it — identical shape whichever provider got here, so
        // "how many orders were placed" stays one query.
        $this->checkoutLogger->info('checkout.order.placed', [
            'order_id'   => $orderId,
            'provider'   => $outcome->provider,
            'reference'  => $outcome->reference,
            'total'      => $order->getTotal(),
            'currency'   => $this->currencyService->forProvider($order->getCurrency()),
            'item_count' => count($order->getItems()),
            'province'   => $order->getShippingProvince(),
            'verified'   => $order->isPaymentVerified(),
        ]);

        return true;
    }

    /**
     * Confirms the amount and currency the provider settled match the order, and
     * flags the order (without refusing it) when they cannot be confirmed. A
     * flagged order is visible, recoverable, and safe as long as nothing ships
     * before someone reviews it — the one outcome worse than a suspicious row is
     * a charged customer with no order at all.
     */
    private function verify(Order $order, PaymentOutcome $outcome): void
    {
        $fail = function (string $issue, array $context = []) use ($order, $outcome): void {
            $order->setPaymentVerified(false);
            $order->setPaymentVerificationIssue($issue);

            $this->paymentLogger->error('payment.verification_failed', array_merge([
                'order_id' => $order->getId(),
                'provider' => $outcome->provider,
                'issue'    => $issue,
                'total'    => $order->getTotal(),
                'action'   => 'order stored but flagged; do not fulfil until reviewed',
            ], $context));
        };

        if (!$outcome->providerConfirmed) {
            $fail('provider_not_confirmed', ['reference' => $outcome->reference]);

            return;
        }

        // Currency must match before the amount means anything: 37799 minor units
        // is one thing in CAD and pocket change in another, so comparing the
        // numbers alone would accept a payment in the wrong currency.
        $expectedCurrency = $this->currencyService->forProvider($order->getCurrency());
        if ($outcome->paidCurrency === null || $outcome->paidCurrency !== $expectedCurrency) {
            $fail('currency_mismatch', [
                'paid_currency'  => $outcome->paidCurrency,
                'order_currency' => $expectedCurrency,
            ]);

            return;
        }

        if ($outcome->paidMinor === null) {
            $fail('amount_missing', ['reference' => $outcome->reference]);

            return;
        }

        $expectedMinor = $this->currencyService->toMinorUnits($order->getTotal(), $order->getCurrency());
        if ($outcome->paidMinor !== $expectedMinor) {
            $fail('amount_mismatch', [
                'received_minor' => $outcome->paidMinor,
                'expected_minor' => $expectedMinor,
                'currency'       => $expectedCurrency,
            ]);

            return;
        }

        $order->setPaymentVerified(true);
        $order->setPaymentVerificationIssue(null);

        $this->paymentLogger->info('payment.verified', [
            'order_id'     => $order->getId(),
            'provider'     => $outcome->provider,
            'reference'    => $outcome->reference,
            'amount_minor' => $outcome->paidMinor,
            'currency'     => $expectedCurrency,
        ]);
    }

    private function sendConfirmationEmail(Order $order): void
    {
        // Send in the customer's preferred language; default to French when
        // unavailable (e.g. guest checkout).
        $locale = $order->getUser()?->getLocale() ?? 'fr';

        $this->localeSwitcher->runWithLocale($locale, function () use ($order, $locale): void {
            $message = (new TemplatedEmail())
                ->from(new EmailAddress('no-reply@monapp.local', 'MonApp'))
                ->to($order->getCustomerEmail())
                ->subject($this->translator->trans('email.order_confirmation.subject'))
                ->htmlTemplate('emails/order_confirmation.html.twig')
                ->context(['order' => $order, 'locale' => $locale]);

            $this->mailer->send($message);
        });
    }
}
