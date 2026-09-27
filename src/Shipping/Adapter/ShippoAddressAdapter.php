<?php

namespace App\Shipping\Adapter;

use App\Shipping\ShippingAddress;

/**
 * Maps a ShippingAddress to the array shape Shippo's Address expects.
 *
 * This is the anti-corruption layer against the vendor: Shippo's field names
 * (`street1`, `state`, `zip`), its ISO-2 lowercase-tolerant country, and its
 * habit of wanting empty strings rather than nulls all stay contained here.
 * Nothing upstream needs to know Shippo calls a postal code `zip`.
 *
 * The returned array is deliberately a plain payload, not a `Shippo_Address`
 * object — building the SDK object (a network call) is the shipping flow's job,
 * kept out of this pure mapping so it stays trivially testable.
 */
final class ShippoAddressAdapter implements CarrierAddressAdapter
{
    public function toPayload(ShippingAddress $address): array
    {
        return [
            'name'    => $address->name,
            'company' => $address->company ?? '',
            'street1' => $address->street1,
            'street2' => $address->street2 ?? '',
            'city'    => $address->city,
            'state'   => $address->state ?? '',
            'zip'     => $address->postalCode ?? '',
            'country' => strtoupper($address->country),
            'phone'   => $address->phone ?? '',
            'email'   => $address->email ?? '',
        ];
    }
}
