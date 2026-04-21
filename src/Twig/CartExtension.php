<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use App\Service\CartService;
use Twig\TwigFilter;
use Twig\TwigFunction;

class CartExtension extends AbstractExtension implements GlobalsInterface
{
    
    private $cartService;
    
    /**
     * Summary of __construct
     * @param CartService $cartService
     */
    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }
public function getFilters(): array
    {
        return [
            new TwigFilter('cart_total', [$this, 'calculateTotal']),
            new TwigFilter('cart_count', [$this, 'countItems']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cart_subtotal', [$this, 'calculateSubtotal']),
            new TwigFunction('cart_tax', [$this, 'calculateTax']),
        ];
    }

    public function getGlobals(): array
    {
        try {
            $itemCount = $this->cartService->getItemCount();
        } catch (\Exception $e) {
            // En cas d'erreur (pas de session par exemple), retourner 0
            $itemCount = 0;
        }

        return [
            'cart_item_count' => $itemCount,
        ];
    }

    public function calculateTotal(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['price'] * $item['quantity'];
        }
        return $total;
    }

    public function countItems(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $count += $item['quantity'] ?? 1;
        }
        return $count;
    }

    public function calculateSubtotal(array $items): float
    {
        return $this->calculateTotal($items);
    }

    public function calculateTax(array $items, float $taxRate = 0.1): float
    {
        return $this->calculateTotal($items) * $taxRate;
    }
}
