<?php

namespace App\Service;

/**
 * Normalised view of what a payment provider reported, independent of whether it
 * came from a Stripe PaymentIntent, a Stripe webhook event, or a PayPal capture.
 *
 * OrderFinalizer compares this against what the order says it should have been
 * paid, so each caller is responsible for translating its provider's payload
 * into these neutral fields (amounts already in the currency's minor units).
 */
final class PaymentOutcome
{
    /**
     * @param string      $provider          'stripe' | 'paypal'
     * @param bool        $providerConfirmed  the provider reports the payment as settled
     *                                        (PaymentIntent succeeded / PayPal COMPLETED)
     * @param string|null $paidCurrency       lowercase ISO code as the provider returns it,
     *                                         null when it could not be read
     * @param int|null    $paidMinor          amount captured in minor units, null when unknown
     * @param string|null $reference          PI id / PayPal order id, for logging
     * @param string|null $paymentMethod      'card', 'paypal', 'link', …
     * @param string|null $paymentBrand       card brand, or payer email for PayPal
     * @param string|null $paymentLast4       card last four digits, when known
     */
    public function __construct(
        public readonly string $provider,
        public readonly bool $providerConfirmed,
        public readonly ?string $paidCurrency,
        public readonly ?int $paidMinor,
        public readonly ?string $reference = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentBrand = null,
        public readonly ?string $paymentLast4 = null,
    ) {
    }
}
