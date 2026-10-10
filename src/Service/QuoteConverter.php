<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderTaxLine;
use App\Entity\Quote;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a mutable {@see Quote} into an immutable {@see Order}, exactly once.
 *
 * The Stripe webhook, the browser return to /checkout/success and the PayPal
 * capture all converge here, so a quote becomes at most one order no matter how
 * many of those paths reach it (a duplicated webhook, or the webhook racing the
 * browser return). The idempotency anchor is the quote's `convertedOrderId`,
 * claimed with a single conditional UPDATE: the caller that wins builds the
 * order, every other caller discards the order it built and returns the winner's.
 *
 * Provider-neutral on purpose: it reads the quote's `(paymentProvider,
 * paymentReference)` pair, never a concrete provider's SDK. It does NOT perform
 * the paid transition — that stays in {@see OrderFinalizer}, which each caller
 * invokes on the returned order with the PaymentOutcome it built from its
 * provider. See docs/quote-lifecycle-plan.md.
 */
class QuoteConverter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly QuoteRepository $quotes,
        private readonly LoggerInterface $paymentLogger,
    ) {
    }

    /**
     * Returns the Order for this quote, creating it on the first call and
     * returning the same one on every later call. Always returns a persisted,
     * `pending` order ready for OrderFinalizer (the first caller) or an order
     * that may already be `paid` (a later caller).
     */
    public function convert(Quote $quote): Order
    {
        $quoteId = $quote->getId();
        if ($quoteId === null) {
            throw new \LogicException('QuoteConverter requires a persisted quote.');
        }

        // Fast path: this quote was already converted — return the existing order.
        $existingOrderId = $quote->getConvertedOrderId();
        if ($existingOrderId !== null) {
            $order = $this->orders->find($existingOrderId);
            if ($order !== null) {
                return $order;
            }
            // The recorded order has vanished (manually deleted?). Fall through and
            // rebuild rather than fail the payment finalization.
            $this->paymentLogger->warning('checkout.quote.converted_order_missing', [
                'quote_id' => $quoteId,
                'order_id' => $existingOrderId,
            ]);
        }

        // Build the immutable order from the quote snapshot. The payment reference
        // is deliberately NOT copied onto the order yet: two racing conversions
        // must not both insert an order carrying the same unique
        // stripe_payment_intent_id. Only the conversion that wins the claim stamps it.
        $order = $this->buildOrder($quote);
        $this->em->persist($order);
        $this->em->flush();

        if ($this->quotes->markConvertedIfNot($quoteId, (int) $order->getId())) {
            // We won the claim: it is now safe to record the provider reference on
            // our order. Order models only a Stripe reference column today, so a
            // PayPal reference lives on the PaymentOutcome/log, not on the order.
            $reference = $quote->getPaymentReference();
            if ($reference !== null && $quote->getPaymentProvider() === 'stripe') {
                $order->setStripePaymentIntentId($reference);
                $this->em->flush();
            }

            // The bulk UPDATE bypassed the identity map; resync the managed quote.
            $this->em->refresh($quote);

            $this->paymentLogger->info('checkout.quote.converted', [
                'quote_id' => $quoteId,
                'order_id' => $order->getId(),
                'provider' => $quote->getPaymentProvider(),
            ]);

            return $order;
        }

        // Lost the race: another conversion already recorded the order. Discard the
        // one we just built and return the winner's.
        $this->em->remove($order);
        $this->em->flush();
        $this->em->refresh($quote);

        $winnerId = $quote->getConvertedOrderId();
        $winner = $winnerId !== null ? $this->orders->find($winnerId) : null;
        if ($winner === null) {
            throw new \RuntimeException(sprintf(
                'Quote %d is marked converted but its order (%s) could not be loaded.',
                $quoteId,
                var_export($winnerId, true),
            ));
        }

        $this->paymentLogger->info('checkout.quote.convert_race_lost', [
            'quote_id'          => $quoteId,
            'winning_order_id'  => $winnerId,
        ]);

        return $winner;
    }

    /**
     * Copies the quote's snapshot into a fresh `pending` Order. Non-nullable order
     * columns are coalesced defensively (the quote should carry full data by the
     * time a payment settles, but a null must never turn a successful payment into
     * a 500). Items and tax lines are copied one-to-one.
     */
    private function buildOrder(Quote $quote): Order
    {
        $order = new Order();
        $order->setUser($quote->getUser());
        $order->setStatus('pending');
        $order->setCurrency($quote->getCurrency());
        $order->setTotal((string) ($quote->getTotal() ?? '0.00'));
        $order->setSubtotal($quote->getSubtotal());
        $order->setShippingAmount($quote->getShippingAmount());
        $order->setShippingMethodCarrier($quote->getShippingMethodCarrier());
        $order->setShippingMethodName($quote->getShippingMethodName());
        $order->setShippingMethodReference($quote->getShippingMethodReference());

        $order->setCustomerFirstName($quote->getCustomerFirstName() ?: 'Client');
        $order->setCustomerLastName($quote->getCustomerLastName() ?? '');
        $order->setCustomerEmail($quote->getCustomerEmail() ?? '');
        $order->setCustomerPhone($quote->getCustomerPhone());

        $order->setShippingStreet($quote->getShippingStreet() ?? '');
        $order->setShippingCity($quote->getShippingCity() ?? '');
        $order->setShippingPostalCode($quote->getShippingPostalCode() ?? '');
        $order->setShippingProvince($quote->getShippingProvince());
        $order->setShippingCountry($quote->getShippingCountry());

        $order->setBillingStreet($quote->getBillingStreet() ?? ($quote->getShippingStreet() ?? ''));
        $order->setBillingCity($quote->getBillingCity() ?? ($quote->getShippingCity() ?? ''));
        $order->setBillingPostalCode($quote->getBillingPostalCode() ?? ($quote->getShippingPostalCode() ?? ''));
        $order->setBillingProvince($quote->getBillingProvince() ?? $quote->getShippingProvince());

        foreach ($quote->getItems() as $quoteItem) {
            $orderItem = new OrderItem();
            $orderItem->setArticle($quoteItem->getArticle());
            $orderItem->setQuantity((int) $quoteItem->getQuantity());
            $orderItem->setUnitPrice((string) $quoteItem->getUnitPrice());
            $orderItem->setSubtotal((string) $quoteItem->getSubtotal());
            $order->addItem($orderItem);
        }

        foreach ($quote->getTaxLines() as $taxLine) {
            $order->addTaxLine(new OrderTaxLine(
                code: $taxLine->getCode(),
                label: $taxLine->getLabel(),
                rate: $taxLine->getRate(),
                amount: $taxLine->getAmount(),
                jurisdiction: $taxLine->getJurisdiction(),
            ));
        }
        $order->setTaxTotal($quote->getTaxTotal());

        return $order;
    }
}
