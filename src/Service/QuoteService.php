<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\Quote;
use App\Entity\QuoteItem;
use App\Entity\QuoteTaxLine;
use App\Entity\User;
use App\Market\MarketContext;
use App\Market\Tax\TaxEngineRegistry;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Owns the mutable pre-payment quote during checkout: creates it and keeps it in
 * sync with the session + cart as the customer progresses. This is what replaced
 * the old pending-Order pre-persist (`CheckoutController::upsertPendingOrder`) —
 * a quote always exists before a payment is attempted, so a payment that settles
 * (webhook, browser return or PayPal capture) can always be converted into an
 * Order, even on a pure browser abandonment.
 *
 * The quote is deliberately provider-neutral: this service never touches the
 * payment linkage. The caller that knows which provider is settling (Stripe PI,
 * PayPal order) sets `(provider, reference)` via Quote::setPayment — see
 * docs/quote-lifecycle-plan.md "Provider/engine independence".
 */
class QuoteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuoteRepository $quotes,
        private readonly TaxEngineRegistry $taxEngineRegistry,
        private readonly MarketContext $marketContext,
    ) {
    }

    /**
     * Creates or updates the session's quote from the current session + cart, and
     * persists it. Returns null (creating nothing) only when the cart is empty —
     * there is nothing to quote. A converted quote is immutable history and is
     * never reused: a fresh one is created instead.
     */
    public function upsert(SessionInterface $session, Cart $cart, ?User $user): ?Quote
    {
        if ($cart->isEmpty()) {
            return null;
        }

        $quote = null;
        $existingId = $session->get('checkout_quote_id');
        if ($existingId) {
            $candidate = $this->quotes->find($existingId);
            if ($candidate && !$candidate->isConverted()) {
                $quote = $candidate;
            }
        }
        if (!$quote) {
            $quote = new Quote();
            $quote->setSessionId($session->getId() ?: null);
        }

        $this->populate($quote, $session, $cart, $user);

        $this->em->persist($quote);
        $this->em->flush();

        $session->set('checkout_quote_id', $quote->getId());

        return $quote;
    }

    /**
     * Fills a quote (new or still-open) from the session and cart. Items and tax
     * lines are rebuilt from scratch each call so a second update (province or
     * shipping change) does not duplicate them. Mirrors the snapshot the order
     * used to be built from, but onto the mutable quote.
     */
    private function populate(Quote $quote, SessionInterface $session, Cart $cart, ?User $user): void
    {
        $name             = (string) $session->get('checkout_name', 'Client');
        $email            = $session->get('checkout_email');
        $phone            = $session->get('checkout_phone');
        $shippingStreet   = $session->get('checkout_shipping_address');
        $shippingCity     = $session->get('checkout_shipping_city');
        $shippingPostal   = $session->get('checkout_shipping_postal');
        $shippingProvince = $session->get('checkout_shipping_province');
        $billingSame      = $session->get('checkout_billing_same', true);
        $billingStreet    = $session->get('checkout_billing_address');
        $billingCity      = $session->get('checkout_billing_city');
        $billingPostal    = $session->get('checkout_billing_postal');
        $billingProvince  = $session->get('checkout_billing_province');

        $grandTotal = $session->get('checkout_grand_total');
        $total      = $grandTotal !== null ? (string) round((float) $grandTotal, 2) : $cart->getTotal();

        $subtotal          = $session->get('checkout_subtotal');
        $shippingAmount    = $session->get('checkout_shipping_amount');
        $shippingCarrier   = $session->get('checkout_shipping_carrier');
        $shippingMethod    = $session->get('checkout_shipping_method');
        $shippingReference = $session->get('checkout_shipping_reference');

        $quote->setUser($user);
        $quote->setCurrency($cart->getCurrency());
        $quote->setTotal($total);
        $quote->setSubtotal($subtotal !== null ? (string) round((float) $subtotal, 2) : $cart->getTotal());
        $quote->setShippingAmount($shippingAmount !== null ? (string) round((float) $shippingAmount, 2) : null);
        $quote->setShippingMethodCarrier($shippingCarrier ?: null);
        $quote->setShippingMethodName($shippingMethod ?: null);
        $quote->setShippingMethodReference($shippingReference ?: null);

        // Rebuild the tax breakdown from the active market's engine, with the same
        // inputs update-payment used, and snapshot the lines onto the quote.
        foreach ($quote->getTaxLines()->toArray() as $existingLine) {
            $quote->removeTaxLine($existingLine);
        }
        $taxCountry = $session->get('checkout_shipping_country') ?: $this->marketContext->homeCountry();
        $taxQuote = $this->taxEngineRegistry->active()->quote(
            (float) ($subtotal ?? $cart->getTotal()),
            $taxCountry,
            $shippingProvince ?: null,
        );
        foreach ($taxQuote->lines as $line) {
            $quote->addTaxLine(new QuoteTaxLine(
                code: $line->code,
                label: $line->label,
                rate: $line->rate,
                amount: $line->amount,
                jurisdiction: $line->jurisdiction,
            ));
        }
        $quote->setTaxTotal($taxQuote->total());

        $nameParts = explode(' ', trim($name), 2);
        $quote->setCustomerFirstName($nameParts[0] ?: 'Client');
        $quote->setCustomerLastName($nameParts[1] ?? '');
        $quote->setCustomerEmail($email ?: null);
        $quote->setCustomerPhone($phone ?: null);
        $quote->setShippingStreet($shippingStreet ?: null);
        $quote->setShippingCity($shippingCity ?: null);
        $quote->setShippingPostalCode($shippingPostal ?: null);
        $quote->setShippingProvince($shippingProvince ?: null);
        $quote->setShippingCountry($session->get('checkout_shipping_country') ?: $this->marketContext->homeCountry());

        if ($billingSame || !$billingStreet) {
            $quote->setBillingStreet($shippingStreet ?: null);
            $quote->setBillingCity($shippingCity ?: null);
            $quote->setBillingPostalCode($shippingPostal ?: null);
            $quote->setBillingProvince($shippingProvince ?: null);
        } else {
            $quote->setBillingStreet($billingStreet);
            $quote->setBillingCity($billingCity ?: $shippingCity ?: null);
            $quote->setBillingPostalCode($billingPostal ?: $shippingPostal ?: null);
            $quote->setBillingProvince($billingProvince ?: $shippingProvince ?: null);
        }

        foreach ($quote->getItems()->toArray() as $existingItem) {
            $quote->removeItem($existingItem);
        }
        foreach ($cart->getItems() as $cartItem) {
            $quoteItem = new QuoteItem();
            $quoteItem->setArticle($cartItem->getArticle());
            $quoteItem->setQuantity($cartItem->getQuantity());
            $quoteItem->setUnitPrice($cartItem->getUnitPrice());
            $quoteItem->setSubtotal(number_format($cartItem->getSubtotal(), 2, '.', ''));
            $quote->addItem($quoteItem);
        }

        // Shipping-ready gate: the quote is ready to pay once it has a deliverable
        // address AND the customer has picked a shipping method (which they can
        // only do after the abstract shipping service returned serviceable rates).
        // Until then it stays a draft. A converted quote is never re-touched here.
        $hasAddress  = ($shippingStreet ?: '') !== '' && ($shippingCity ?: '') !== '' && ($shippingPostal ?: '') !== '';
        $hasShipping = ($shippingReference ?: '') !== '' || $shippingAmount !== null;
        if (!$quote->isConverted()) {
            if ($hasAddress && $hasShipping) {
                $quote->markReady();
            } else {
                $quote->markDraft();
            }
        }
    }
}
