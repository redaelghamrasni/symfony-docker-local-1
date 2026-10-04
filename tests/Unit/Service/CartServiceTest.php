<?php

namespace App\Tests\Unit\Service;

use App\Entity\Cart;
use App\Repository\CartRepository;
use App\Service\CartService;
use App\Service\CurrencyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CartServiceTest extends TestCase
{
    private CartService $cartService;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $cartRepository = $this->createMock(CartRepository::class);
        $cartRepository->method('find')->willReturn(null);
        $cartRepository->method('findOneBy')->willReturn(null);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturn(null);
        $session->method('set');

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $currencyService = $this->createMock(CurrencyService::class);
        $currencyService->method('default')->willReturn('CAD');

        $this->cartService = new CartService($em, $cartRepository, $requestStack, $currencyService);
    }

    public function testGetCurrentCartReturnsCart(): void
    {
        $cart = $this->cartService->getCurrentCart();
        $this->assertInstanceOf(Cart::class, $cart);
    }

    public function testCartStartsEmpty(): void
    {
        $cart = $this->cartService->getCurrentCart();
        $this->assertCount(0, $cart->getItems());
    }
}