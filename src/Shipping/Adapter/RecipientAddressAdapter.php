<?php

namespace App\Shipping\Adapter;

use App\Entity\Order;
use App\Shipping\ShippingAddress;

/**
 * Builds the recipient address — where a parcel ships *to* — from an Order.
 *
 * The Order already snapshots the shipping address at purchase time (street,
 * city, postal code, province, customer name and contact), so an order carries
 * everything a recipient address needs — except a country.
 *
 * ── The architectural gap ───────────────────────────────────────────────────
 * Neither Order nor Address has a country column: the shop is Canada-only, and
 * `province` is stored without a country beside it. International shipping is
 * impossible until a country is captured at checkout and snapshotted on the
 * order, exactly as province is today.
 *
 * Rather than hide that, this adapter takes the country explicitly and defaults
 * it to CA. When the country is added to the order, the default disappears and
 * this reads `$order->getShippingCountry()` — nothing else here changes. The
 * default keeps existing (domestic) orders working in the meantime.
 */
final class RecipientAddressAdapter
{
    private const DEFAULT_COUNTRY = 'CA';

    public function fromOrder(Order $order, ?string $countryOverride = null): ShippingAddress
    {
        $name = trim(($order->getCustomerFirstName() ?? '') . ' ' . ($order->getCustomerLastName() ?? ''));

        return new ShippingAddress(
            name:       $name !== '' ? $name : 'Customer',
            street1:    (string) $order->getShippingStreet(),
            city:       (string) $order->getShippingCity(),
            // TODO(country): read $order->getShippingCountry() once orders carry it.
            country:    $countryOverride ?? self::DEFAULT_COUNTRY,
            state:      $order->getShippingProvince(),
            postalCode: $order->getShippingPostalCode(),
            phone:      $order->getCustomerPhone(),
            email:      $order->getCustomerEmail(),
        );
    }
}
