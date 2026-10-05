<?php

namespace App\Shipping\Adapter;

use App\Entity\Cart;
use App\Shipping\Customs\CustomsDeclaration;
use App\Shipping\Customs\CustomsItem;

/**
 * Builds a customs declaration from a cart — the inbound side, like
 * RecipientAddressAdapter builds the recipient from an order.
 *
 * Each cart line becomes one customs item: the article's title describes it,
 * its unit price times the quantity is the declared value, and the quantity
 * times a per-unit default is the net weight — the same 0.5 kg/item heuristic
 * the parcel estimate already uses, since the catalog carries no real weight.
 *
 * Goods are declared as originating in the shop's country (passed in), because
 * the catalog has no per-article country of origin. An operator shipping goods
 * made elsewhere refines this once the catalog gains that field — the one place
 * to change is here, not the checkout.
 */
final class CartCustomsAdapter
{
    /** Default net weight per unit, in kilograms (matches the parcel estimate). */
    private const WEIGHT_PER_UNIT_KG = 0.5;

    public function fromCart(Cart $cart, string $originCountry): CustomsDeclaration
    {
        $items = [];

        foreach ($cart->getItems() as $line) {
            $article  = $line->getArticle();
            $quantity = max(1, $line->getQuantity());

            $items[] = new CustomsItem(
                description:   $article?->getTitle() ?: 'Item',
                quantity:      $quantity,
                netWeightKg:   self::WEIGHT_PER_UNIT_KG * $quantity,
                valueAmount:   round((float) $line->getUnitPrice() * $quantity, 2),
                valueCurrency: $cart->getCurrency(),
                originCountry: strtoupper($originCountry),
                sku:           $article?->getSku(),
            );
        }

        return new CustomsDeclaration($items);
    }
}
