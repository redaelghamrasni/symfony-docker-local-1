<?php

namespace App\Shipping\Adapter;

use App\Shipping\ShippingAddress;

/**
 * Outbound port: turns our neutral ShippingAddress into whatever shape a
 * specific carrier's API expects.
 *
 * This interface is the seam that keeps the carrier swappable. ShippingService
 * (or whatever orchestrates a shipment) depends on this, not on Shippo, so
 * moving to EasyPost or a direct carrier API means writing one new
 * implementation — the value object and the inbound adapters are untouched.
 */
interface CarrierAddressAdapter
{
    /**
     * @return array<string, string> the address in the carrier's own field names
     */
    public function toPayload(ShippingAddress $address): array;
}
