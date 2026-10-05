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
 * its unit price times the quantity is the declared value, and its weight times
 * the quantity is the net weight. The article now carries a real weight (0.5 kg
 * by default until an operator sets it), so the declaration reflects the catalog
 * rather than a flat guess.
 *
 * Goods are declared as originating in the shop's country (passed in), because
 * the catalog has no per-article country of origin. An operator shipping goods
 * made elsewhere refines this once the catalog gains that field — the one place
 * to change is here, not the checkout.
 */
final class CartCustomsAdapter
{
    /** Net weight per unit (kg) when an article somehow carries no weight. */
    private const WEIGHT_PER_UNIT_FALLBACK_KG = 0.5;

    public function fromCart(Cart $cart, string $originCountry): CustomsDeclaration
    {
        $items = [];

        foreach ($cart->getItems() as $line) {
            $article  = $line->getArticle();
            $quantity = max(1, $line->getQuantity());
            $unitKg   = $article !== null ? (float) $article->getWeight() : self::WEIGHT_PER_UNIT_FALLBACK_KG;
            if ($unitKg <= 0) {
                $unitKg = self::WEIGHT_PER_UNIT_FALLBACK_KG;
            }

            $items[] = new CustomsItem(
                description:   $article?->getTitle() ?: 'Item',
                quantity:      $quantity,
                netWeightKg:   round($unitKg * $quantity, 3),
                valueAmount:   round((float) $line->getUnitPrice() * $quantity, 2),
                valueCurrency: $cart->getCurrency(),
                originCountry: strtoupper($originCountry),
                sku:           $article?->getSku(),
            );
        }

        return new CustomsDeclaration($items);
    }
}
