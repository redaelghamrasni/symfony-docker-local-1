<?php

namespace App\Shipping\Adapter;

use App\Shipping\ShippingAddress;

/**
 * Builds the sender (origin) address — where parcels ship *from*.
 *
 * Today this is the address that was hardcoded inline in ShippingService. It is
 * lifted out into one place so there is a single, correct origin, and so it can
 * later be sourced from admin settings (SettingService) instead of a constant.
 * For international shipping the origin must be a real, complete address: it is
 * what customs and the carrier bill and, on a return, ship back to.
 *
 * The values are injected (constructor args), so wiring them to config or the
 * database is a change here, not in the shipping flow.
 */
final class SenderAddressAdapter
{
    /**
     * @param array{
     *     name: string, street1: string, city: string, country: string,
     *     state?: ?string, postalCode?: ?string, street2?: ?string,
     *     phone?: ?string, email?: ?string, company?: ?string
     * } $origin
     */
    public function __construct(private readonly array $origin)
    {
    }

    public function address(): ShippingAddress
    {
        return new ShippingAddress(
            name:       $this->origin['name'],
            street1:    $this->origin['street1'],
            city:       $this->origin['city'],
            country:    $this->origin['country'],
            state:      $this->origin['state'] ?? null,
            postalCode: $this->origin['postalCode'] ?? null,
            street2:    $this->origin['street2'] ?? null,
            phone:      $this->origin['phone'] ?? null,
            email:      $this->origin['email'] ?? null,
            company:    $this->origin['company'] ?? null,
        );
    }
}
