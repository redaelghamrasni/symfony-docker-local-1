<?php

namespace App\Tests\Unit\Controller;

use App\Controller\StripeWebhookController;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\OrderFinalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook's security boundary: a payload is only ever processed when its
 * signature verifies against the shared secret. An unsigned or forged request
 * is rejected (400) and nothing is finalized.
 */
class StripeWebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function makeController(OrderFinalizer $finalizer, ?OrderRepository $orders = null): StripeWebhookController
    {
        return new StripeWebhookController(
            $orders ?? $this->createMock(OrderRepository::class),
            $finalizer,
            $this->createMock(LoggerInterface::class),
            self::SECRET,
        );
    }

    private function signedRequest(string $payload): Request
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);

        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('Stripe-Signature', sprintf('t=%d,v1=%s', $timestamp, $signature));

        return $request;
    }

    public function testMissingSignatureIsRejectedWithoutProcessing(): void
    {
        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->never())->method('finalizePaid');

        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], '{"type":"payment_intent.succeeded"}');

        $response = $this->makeController($finalizer)->handle($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBadSignatureIsRejectedWithoutProcessing(): void
    {
        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->never())->method('finalizePaid');

        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], '{"type":"payment_intent.succeeded"}');
        $request->headers->set('Stripe-Signature', 't=1,v1=deadbeef');

        $response = $this->makeController($finalizer)->handle($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testValidSignedSucceededEventFinalizesTheOrder(): void
    {
        $payload = json_encode([
            'id'   => 'evt_1',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id'                   => 'pi_123',
                'object'               => 'payment_intent',
                'status'               => 'succeeded',
                'currency'             => 'cad',
                'amount_received'      => 1000,
                'payment_method_types' => ['card'],
                'metadata'             => ['order_id' => '42'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $order = $this->createMock(Order::class);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('findOneByStripePaymentIntentId')->with('pi_123')->willReturn($order);

        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->once())
            ->method('finalizePaid')
            ->with($this->identicalTo($order), $this->anything())
            ->willReturn(true);

        $response = $this->makeController($finalizer, $orders)->handle($this->signedRequest($payload));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testValidSignedUnrelatedEventIsAcknowledgedWithoutProcessing(): void
    {
        $payload = json_encode([
            'id'   => 'evt_2',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id'     => 'pi_fail',
                'object' => 'payment_intent',
                'status' => 'requires_payment_method',
            ]],
        ], JSON_THROW_ON_ERROR);

        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->never())->method('finalizePaid');

        $response = $this->makeController($finalizer)->handle($this->signedRequest($payload));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
