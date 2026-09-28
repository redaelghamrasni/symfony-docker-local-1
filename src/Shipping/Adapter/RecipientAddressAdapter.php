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
 * The order now snapshots the destination country (Order::getShippingCountry(),
 * defaulting to CA for orders placed before international shipping existed), so
 * the recipient is fully derived from the order. An override is still accepted
 * for the rare case of re-rating to a corrected address without mutating the
 * stored order.
 */
final class RecipientAddressAdapter
{
    public function fromOrder(Order $order, ?string $countryOverride = null): ShippingAddress
    {
        $name = trim(($order->getCustomerFirstName() ?? '') . ' ' . ($order->getCustomerLastName() ?? ''));

        return new ShippingAddress(
            name:       $name !== '' ? $name : 'Customer',
            street1:    (string) $order->getShippingStreet(),
            city:       (string) $order->getShippingCity(),
            country:    $countryOverride ?: $order->getShippingCountry(),
            state:      $order->getShippingProvince(),
            postalCode: $order->getShippingPostalCode(),
            phone:      $order->getCustomerPhone(),
            email:      $order->getCustomerEmail(),
        );
    }
}
