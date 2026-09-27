<?php

namespace App\Shipping;

/**
 * A postal address in the shape shipping needs — vendor-neutral.
 *
 * This is the *port*: our own representation of an address for shipping,
 * knowing nothing about Shippo (or any future carrier). Data flows into it
 * from our domain (an Order, an origin config) through the inbound adapters,
 * and out of it to a carrier through an outbound adapter. Keeping this object
 * ignorant of the carrier is the whole point — swapping Shippo for another
 * provider touches one adapter, not the checkout.
 *
 * Immutable on purpose: an address handed to a rate request or a label must
 * not change under it.
 *
 * `country` is a first-class, required field (ISO 3166-1 alpha-2), because
 * that is exactly what domestic-only code omits and international shipping
 * cannot do without. `state` and `postalCode` are optional here: they are
 * required in Canada and the US but meaningless in parts of the world, so
 * that rule belongs to the carrier, not to this structural type.
 *
 * `phone` is optional at construction because Shippo does not require it for
 * domestic rating — but its international rating guide makes it mandatory on
 * *both* the sender and the recipient just to get rates. That conditional rule
 * is enforced by assertReadyForInternational(), called on both addresses when
 * a shipment crosses a border, rather than by forcing every domestic address
 * to carry a phone. See https://docs.goshippo.com/stories/intl-rating-guide
 */
final readonly class ShippingAddress
{
    public function __construct(
        public string $name,
        public string $street1,
        public string $city,
        public string $country,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $street2 = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $company = null,
    ) {
        foreach (['name' => $name, 'street1' => $street1, 'city' => $city, 'country' => $country] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(sprintf('ShippingAddress requires a non-empty "%s".', $field));
            }
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $country)) {
            throw new \InvalidArgumentException(sprintf('Country must be an ISO 3166-1 alpha-2 code, got "%s".', $country));
        }
    }

    /** True when the address leaves the origin country — the case that needs customs data. */
    public function isInternationalFrom(ShippingAddress $origin): bool
    {
        return strtoupper($this->country) !== strtoupper($origin->country);
    }

    public function hasPhone(): bool
    {
        return $this->phone !== null && trim($this->phone) !== '';
    }

    /**
     * Enforces the extra field Shippo requires for international rating.
     *
     * Per the intl rating guide, a phone number is mandatory on both the sender
     * and the recipient for an international shipment — a missing one makes the
     * rate call fail. The shipment orchestration calls this on both addresses
     * once it knows the shipment is international, keeping the rule out of the
     * domestic path.
     *
     * @param string $role "sender" or "recipient", for a clear error message
     * @throws \DomainException when a phone number is missing
     */
    public function assertReadyForInternational(string $role = 'address'): void
    {
        if (!$this->hasPhone()) {
            throw new \DomainException(sprintf(
                'International shipping requires a phone number on the %s (Shippo intl rating requirement).',
                $role
            ));
        }
    }
}
