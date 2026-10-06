<?php

namespace App\Tests\Unit\Service;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\CurrencyService;
use App\Service\OrderFinalizer;
use App\Service\PaymentOutcome;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contract that makes the Stripe webhook safe: the pending → paid transition
 * happens exactly once per order, and it only trusts a payment whose amount and
 * currency actually match the order.
 */
class OrderFinalizerTest extends TestCase
{
    private function makeOrder(int $id, string $total = '10.00', string $currency = 'CAD'): Order
    {
        $order = new Order();
        $order->setStatus('pending');
        $order->setTotal($total);
        $order->setCurrency($currency);
        $order->setCustomerEmail('customer@example.com');

        // The id is DB-generated; set it directly so the finalizer can key on it.
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, $id);

        return $order;
    }

    private function makeCurrencyService(): CurrencyService
    {
        $currency = $this->createMock(CurrencyService::class);
        $currency->method('forProvider')->willReturnCallback(fn (string $c) => strtolower($c));
        $currency->method('toMinorUnits')->willReturnCallback(
            fn (string|float $amount, string $c) => (int) round(((float) $amount) * 100)
        );

        return $currency;
    }

    private function makeFinalizer(
        OrderRepository $orders,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
        MailerInterface $mailer,
    ): OrderFinalizer {
        $localeSwitcher = $this->createMock(LocaleSwitcher::class);
        // runWithLocale must actually run the callback so the mail is "sent".
        $localeSwitcher->method('runWithLocale')->willReturnCallback(
            fn (string $locale, callable $cb) => $cb()
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Order confirmation');

        $logger = $this->createMock(LoggerInterface::class);

        return new OrderFinalizer(
            $orders,
            $em,
            $bus,
            $this->makeCurrencyService(),
            $mailer,
            $localeSwitcher,
            $translator,
            $logger,
            $logger,
        );
    }

    public function testFinalizeIsIdempotentAcrossTwoCalls(): void
    {
        $order = $this->makeOrder(42);

        $orders = $this->createMock(OrderRepository::class);
        // The atomic claim succeeds once (this caller wins), then fails (the
        // order is already paid) — exactly the webhook/browser race.
        $orders->method('markPaidIfPending')->willReturnOnConsecutiveCalls(true, false);

        $em = $this->createMock(EntityManagerInterface::class);
        // Flush must happen only on the winning call.
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $mailer = $this->createMock(MailerInterface::class);
        // One order → one confirmation email, no matter how many callers arrive.
        $mailer->expects($this->once())->method('send');

        $finalizer = $this->makeFinalizer($orders, $em, $bus, $mailer);

        $outcome = new PaymentOutcome('stripe', true, 'cad', 1000, 'pi_123');

        $this->assertTrue($finalizer->finalizePaid($order, $outcome), 'first caller performs the transition');
        $this->assertFalse($finalizer->finalizePaid($order, $outcome), 'second caller is a no-op');
    }

    public function testVerifiedWhenAmountAndCurrencyMatch(): void
    {
        $order = $this->makeOrder(1, '25.50', 'CAD');

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('markPaidIfPending')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $mailer = $this->createMock(MailerInterface::class);

        $finalizer = $this->makeFinalizer($orders, $em, $bus, $mailer);

        $outcome = new PaymentOutcome('stripe', true, 'cad', 2550, 'pi_ok');
        $this->assertTrue($finalizer->finalizePaid($order, $outcome));
        $this->assertTrue($order->isPaymentVerified());
        $this->assertNull($order->getPaymentVerificationIssue());
    }

    public function testFlaggedWhenCapturedAmountMismatches(): void
    {
        $order = $this->makeOrder(2, '25.50', 'CAD');

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('markPaidIfPending')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $mailer = $this->createMock(MailerInterface::class);

        $finalizer = $this->makeFinalizer($orders, $em, $bus, $mailer);

        // Stripe captured $0.01 for a $25.50 order: stored, but flagged.
        $outcome = new PaymentOutcome('stripe', true, 'cad', 1, 'pi_bad');
        $this->assertTrue($finalizer->finalizePaid($order, $outcome));
        $this->assertFalse($order->isPaymentVerified());
        $this->assertSame('amount_mismatch', $order->getPaymentVerificationIssue());
    }

    public function testFlaggedWhenCurrencyMismatches(): void
    {
        $order = $this->makeOrder(3, '25.50', 'CAD');

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('markPaidIfPending')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $mailer = $this->createMock(MailerInterface::class);

        $finalizer = $this->makeFinalizer($orders, $em, $bus, $mailer);

        $outcome = new PaymentOutcome('stripe', true, 'usd', 2550, 'pi_usd');
        $this->assertTrue($finalizer->finalizePaid($order, $outcome));
        $this->assertFalse($order->isPaymentVerified());
        $this->assertSame('currency_mismatch', $order->getPaymentVerificationIssue());
    }
}
