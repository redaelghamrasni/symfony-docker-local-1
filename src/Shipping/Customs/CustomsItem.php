<?php

namespace App\Shipping\Customs;

/**
 * One line of a customs declaration — vendor-neutral, like ShippingAddress.
 *
 * A cross-border parcel must declare what is inside: what each item is, how
 * many, what it weighs, what it is worth, and where it was made. Customs and
 * the carrier read this to assess duties and clear the shipment. Keeping it in
 * our own shape (not Shippo's `Shippo_CustomsItem`) means the carrier mapping
 * stays in one adapter and the checkout never learns Shippo's field names.
 *
 * `netWeightKg` and `valueAmount` are per line (quantity included), matching
 * what carriers expect: the total weight and total value of this line, not the
 * per-unit figures.
 *
 * `hsCode` (Harmonized System tariff number) is optional: it is not held on the
 * catalog today, and Shippo rates and ships without it, so it is omitted rather
 * than faked. An operator who needs precise duty assessment adds it later.
 */
final readonly class CustomsItem
{
    public function __construct(
        public string $description,
        public int $quantity,
        public float $netWeightKg,
        public float $valueAmount,
        public string $valueCurrency,
        public string $originCountry,
        public ?string $sku = null,
        public ?string $hsCode = null,
    ) {
        if (trim($description) === '') {
            throw new \InvalidArgumentException('A customs item needs a description.');
        }

        if ($quantity < 1) {
            throw new \InvalidArgumentException('A customs item needs a quantity of at least 1.');
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $originCountry)) {
            throw new \InvalidArgumentException(sprintf('Origin country must be an ISO 3166-1 alpha-2 code, got "%s".', $originCountry));
        }
    }
}
