<?php

namespace App\Shipping\Customs;

/**
 * A customs declaration for an international parcel — vendor-neutral.
 *
 * Groups the CustomsItem lines with the shipment-level choices a carrier needs:
 * what kind of contents these are, and what happens if the parcel cannot be
 * delivered. The signer and the carrier-specific "certify" flag are supplied by
 * the carrier mapping (it knows the sender), so this object stays pure data the
 * checkout can build without touching Shippo.
 *
 * Defaults match a normal retail shipment: the contents are merchandise, and an
 * undeliverable parcel is returned rather than abandoned.
 */
final readonly class CustomsDeclaration
{
    public const CONTENTS_MERCHANDISE = 'MERCHANDISE';
    public const NON_DELIVERY_RETURN = 'RETURN';

    /** @param CustomsItem[] $items */
    public function __construct(
        public array $items,
        public string $contentsType = self::CONTENTS_MERCHANDISE,
        public string $nonDeliveryOption = self::NON_DELIVERY_RETURN,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
