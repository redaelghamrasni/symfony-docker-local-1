<?php

namespace App\Tests\Unit\Service;

use App\Entity\Order;
use App\Entity\Quote;
use App\Entity\QuoteTaxLine;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use App\Service\QuoteConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The converter's contract: exactly one Order per Quote. Whichever path reaches a
 * quote first builds the order; every later path returns that same order instead
 * of creating a second. See docs/quote-lifecycle-plan.md.
 */
class QuoteConverterTest extends TestCase
{
    private function makeQuote(int $id): Quote
    {
        $quote = new Quote();
        (new \ReflectionProperty(Quote::class, 'id'))->setValue($quote, $id);

        $quote->setCurrency('CAD');
        $quote->setTotal('37.79');
        $quote->setSubtotal('30.00');
        $quote->setCustomerEmail('buyer@example.com');
        $quote->setShippingStreet('1 Main St');
        $quote->setShippingCity('Toronto');
        $quote->setShippingPostalCode('M5H 2N2');

        $quote->addTaxLine(new QuoteTaxLine('hst', 'HST', '0.13000', '3.90', 'ON'));

        return $quote;
    }

    public function testAlreadyConvertedReturnsExistingOrderWithoutCreatingAnother(): void
    {
        $quote = $this->makeQuote(7);
        $quote->markConverted(5);

        $existing = $this->createMock(Order::class);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('find')->with(5)->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $quotes = $this->createMock(QuoteRepository::class);
        $quotes->expects($this->never())->method('markConvertedIfNot');

        $converter = new QuoteConverter($em, $orders, $quotes, $this->createMock(LoggerInterface::class));

        $this->assertSame($existing, $converter->convert($quote));
    }

    public function testFirstConversionBuildsPendingOrderAndStampsStripeReference(): void
    {
        $quote = $this->makeQuote(7);
        $quote->setPayment('stripe', 'pi_abc');

        $built = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (object $o) use (&$built): void {
                $built = $o;
            });

        $orders = $this->createMock(OrderRepository::class);

        $quotes = $this->createMock(QuoteRepository::class);
        // This caller wins the claim.
        $quotes->method('markConvertedIfNot')->willReturn(true);

        $converter = new QuoteConverter($em, $orders, $quotes, $this->createMock(LoggerInterface::class));

        $order = $converter->convert($quote);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame($built, $order);
        $this->assertSame('pending', $order->getStatus());
        $this->assertSame('CAD', $order->getCurrency());
        $this->assertSame('buyer@example.com', $order->getCustomerEmail());
        // The winner stamps the Stripe reference on its order.
        $this->assertSame('pi_abc', $order->getStripePaymentIntentId());
        // The HST line was copied across.
        $this->assertCount(1, $order->getTaxLines());
    }

    public function testLostRaceDiscardsItsOrderAndReturnsTheWinner(): void
    {
        $quote = $this->makeQuote(7);
        $quote->setPayment('stripe', 'pi_abc');

        $winner = $this->createMock(Order::class);

        $removed = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$removed): void {
            // remember what we built so we can assert it is the one removed
            $removed = $o;
        });
        // When the claim is lost, the converter refreshes the quote; simulate the
        // winner having recorded its order id by then.
        $em->method('refresh')->willReturnCallback(function (object $q): void {
            if ($q instanceof Quote) {
                $q->markConverted(9);
            }
        });
        $removeCalls = [];
        $em->method('remove')->willReturnCallback(function (object $o) use (&$removeCalls): void {
            $removeCalls[] = $o;
        });

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('find')->with(9)->willReturn($winner);

        $quotes = $this->createMock(QuoteRepository::class);
        // This caller loses the claim.
        $quotes->method('markConvertedIfNot')->willReturn(false);

        $converter = new QuoteConverter($em, $orders, $quotes, $this->createMock(LoggerInterface::class));

        $result = $converter->convert($quote);

        $this->assertSame($winner, $result);
        // The order we built was discarded, not returned.
        $this->assertNotSame($winner, $removed);
        $this->assertContains($removed, $removeCalls);
    }
}
