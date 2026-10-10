<?php

namespace App\Tests\Unit\Controller;

use App\Controller\StripeWebhookController;
use App\Entity\Order;
use App\Entity\Quote;
use App\Repository\QuoteRepository;
use App\Service\OrderFinalizer;
use App\Service\QuoteConverter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook's security boundary: a payload is only ever processed when its
 * signature verifies against the shared secret. An unsigned or forged request
 * is rejected (400) and nothing is converted or finalized. A valid succeeded
 * event finds the quote, converts it to an order (idempotently) and finalizes it.
 */
class StripeWebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function makeController(
        OrderFinalizer $finalizer,
        ?QuoteRepository $quotes = null,
        ?QuoteConverter $converter = null,
    ): StripeWebhookController {
        return new StripeWebhookController(
            $quotes ?? $this->createMock(QuoteRepository::class),
            $converter ?? $this->createMock(QuoteConverter::class),
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

    public function testValidSignedSucceededEventConvertsQuoteAndFinalizesOrder(): void
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
                'metadata'             => ['quote_id' => '7'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $quote = $this->createMock(Quote::class);
        $order = $this->createMock(Order::class);

        $quotes = $this->createMock(QuoteRepository::class);
        $quotes->method('findOneByPaymentReference')->with('stripe', 'pi_123')->willReturn($quote);

        $converter = $this->createMock(QuoteConverter::class);
        $converter->expects($this->once())
            ->method('convert')
            ->with($this->identicalTo($quote))
            ->willReturn($order);

        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->once())
            ->method('finalizePaid')
            ->with($this->identicalTo($order), $this->anything())
            ->willReturn(true);

        $response = $this->makeController($finalizer, $quotes, $converter)->handle($this->signedRequest($payload));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSucceededEventWithNoMatchingQuoteDoesNotConvert(): void
    {
        $payload = json_encode([
            'id'   => 'evt_3',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id'                   => 'pi_orphan',
                'object'               => 'payment_intent',
                'status'               => 'succeeded',
                'currency'             => 'cad',
                'amount_received'      => 500,
                'payment_method_types' => ['card'],
                'metadata'             => [],
            ]],
        ], JSON_THROW_ON_ERROR);

        $quotes = $this->createMock(QuoteRepository::class);
        $quotes->method('findOneByPaymentReference')->willReturn(null);

        $converter = $this->createMock(QuoteConverter::class);
        $converter->expects($this->never())->method('convert');

        $finalizer = $this->createMock(OrderFinalizer::class);
        $finalizer->expects($this->never())->method('finalizePaid');

        // Acknowledged (200) so Stripe stops retrying; the orphan is logged for
        // the purge/reconciliation command to recover if a quote appears.
        $response = $this->makeController($finalizer, $quotes, $converter)->handle($this->signedRequest($payload));

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
