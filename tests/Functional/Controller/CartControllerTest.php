<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CartControllerTest extends WebTestCase
{
    
    public function testCartIndexIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/cart');

        $this->assertResponseIsSuccessful();
    }

    public function testAddToCartRequiresPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/cart/add/1');

        // GET sur une route POST → 405 Method Not Allowed
        $this->assertResponseStatusCodeSame(405);
    }

    public function testClearCartRedirects(): void
    {
        $client = static::createClient();
        $client->request('POST', '/fr/cart/clear');

        $this->assertResponseRedirects();
    }
}