<?php

namespace App\Service;

/**
 * The single authority on which currencies this shop accepts, and on how an
 * amount is expressed to a payment provider.
 *
 * Currency is never taken from the request. A client-supplied currency would
 * let someone pay the same integer amount in a cheaper unit — 37799 minor
 * units is CA$377.99 but only a few dollars in some currencies — so it is
 * resolved server-side, stored on the cart, and snapshot onto the order.
 *
 * Today only CAD is genuinely supported: prices, tax rates (GST/PST/HST) and
 * Shippo rates are all Canadian. Adding a currency is a configuration change
 * here plus the pricing work to back it — deliberately not a code change
 * scattered across the checkout.
 */
class CurrencyService
{
    /**
     * Currencies with no minor unit: Stripe expects the amount as-is rather
     * than multiplied by 100. Getting this wrong charges 100x or 1/100th, so
     * it is handled here once rather than at each call site.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * @param string[] $supported ISO 4217 codes this shop accepts
     */
    public function __construct(
        private readonly array $supported,
        private readonly string $default,
    ) {
    }

    /** @return string[] */
    public function supported(): array
    {
        return $this->supported;
    }

    public function default(): string
    {
        return strtoupper($this->default);
    }

    public function isSupported(mixed $code): bool
    {
        return is_string($code) && in_array(strtoupper($code), $this->supported, true);
    }

    /**
     * Normalises a candidate code, falling back to the default.
     *
     * Takes mixed rather than string on purpose: values reaching here may come
     * from stored data or an external payload, and an array would otherwise
     * raise a TypeError inside strtoupper().
     */
    public function normalize(mixed $code): string
    {
        return $this->isSupported($code) ? strtoupper((string) $code) : $this->default();
    }

    /** Lowercase form, which is what Stripe and PayPal expect in payloads. */
    public function forProvider(string $code): string
    {
        return strtolower($this->normalize($code));
    }

    /**
     * Converts a decimal amount to the provider's smallest unit.
     *
     * Accepts a string because monetary values are stored as decimal strings
     * in this codebase (Order::getTotal()), never as floats.
     */
    public function toMinorUnits(string|float $amount, string $code): int
    {
        $value = (float) $amount;

        return $this->hasMinorUnit($code)
            ? (int) round($value * 100)
            : (int) round($value);
    }

    /** The inverse, for comparing what a provider reports back to us. */
    public function fromMinorUnits(int $minor, string $code): float
    {
        return $this->hasMinorUnit($code) ? $minor / 100 : (float) $minor;
    }

    private function hasMinorUnit(string $code): bool
    {
        return !in_array(strtoupper($code), self::ZERO_DECIMAL, true);
    }
}
