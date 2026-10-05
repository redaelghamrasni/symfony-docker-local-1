<?php

namespace App\Tests\Unit\Shipping\Adapter;

use App\Entity\Article;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Shipping\Adapter\CartCustomsAdapter;
use PHPUnit\Framework\TestCase;

class CartCustomsAdapterTest extends TestCase
{
    public function testBuildsOneCustomsLinePerCartItem(): void
    {
        $cart = $this->cart('CAD', [
            ['title' => 'Wireless mouse', 'sku' => 'MOUSE-1', 'qty' => 2, 'unit' => '29.99', 'weight' => '0.250'],
            ['title' => 'USB cable',      'sku' => 'CABLE-1', 'qty' => 1, 'unit' => '9.50',  'weight' => '0.100'],
        ]);

        $declaration = (new CartCustomsAdapter())->fromCart($cart, 'CA');

        $this->assertCount(2, $declaration->items);

        $mouse = $declaration->items[0];
        $this->assertSame('Wireless mouse', $mouse->description);
        $this->assertSame('MOUSE-1', $mouse->sku);
        $this->assertSame(2, $mouse->quantity);
        $this->assertSame(59.98, $mouse->valueAmount);   // unit * qty
        $this->assertSame(0.5, $mouse->netWeightKg);      // article weight (0.25) * qty
        $this->assertSame('CAD', $mouse->valueCurrency);
    }

    public function testFallsBackTo05kgWhenAnArticleHasNoWeight(): void
    {
        $cart = $this->cart('CAD', [
            ['title' => 'Mystery box', 'sku' => null, 'qty' => 3, 'unit' => '5.00', 'weight' => '0'],
        ]);

        // A zero/blank article weight should not produce a zero-weight parcel.
        $this->assertSame(1.5, (new CartCustomsAdapter())->fromCart($cart, 'CA')->items[0]->netWeightKg);
    }

    public function testDeclaresGoodsAsMadeInTheGivenOriginCountry(): void
    {
        $cart = $this->cart('USD', [['title' => 'Item', 'sku' => null, 'qty' => 1, 'unit' => '10.00']]);

        $declaration = (new CartCustomsAdapter())->fromCart($cart, 'us');

        $this->assertSame('US', $declaration->items[0]->originCountry);  // uppercased
    }

    public function testAnEmptyCartYieldsAnEmptyDeclaration(): void
    {
        $declaration = (new CartCustomsAdapter())->fromCart($this->cart('CAD', []), 'CA');

        $this->assertTrue($declaration->isEmpty());
    }

    /**
     * @param list<array{title: string, sku: ?string, qty: int, unit: string, weight?: string}> $lines
     */
    private function cart(string $currency, array $lines): Cart
    {
        $items = [];
        foreach ($lines as $line) {
            $article = $this->createMock(Article::class);
            $article->method('getTitle')->willReturn($line['title']);
            $article->method('getSku')->willReturn($line['sku']);
            $article->method('getWeight')->willReturn($line['weight'] ?? '0.500');

            $item = $this->createMock(CartItem::class);
            $item->method('getArticle')->willReturn($article);
            $item->method('getQuantity')->willReturn($line['qty']);
            $item->method('getUnitPrice')->willReturn($line['unit']);

            $items[] = $item;
        }

        $cart = $this->createMock(Cart::class);
        $cart->method('getItems')->willReturn($items);
        $cart->method('getCurrency')->willReturn($currency);

        return $cart;
    }
}
