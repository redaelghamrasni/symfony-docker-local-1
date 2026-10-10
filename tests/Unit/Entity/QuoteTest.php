<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Quote;
use App\Entity\QuoteTaxLine;
use PHPUnit\Framework\TestCase;

class QuoteTest extends TestCase
{
    public function testNewQuoteStartsAsDraftWithTimestamps(): void
    {
        $quote = new Quote();

        $this->assertSame(Quote::STATUS_DRAFT, $quote->getStatus());
        $this->assertFalse($quote->isConverted());
        $this->assertNotNull($quote->getCreatedAt());
        $this->assertNotNull($quote->getUpdatedAt());
        $this->assertNull($quote->getReadyAt());
        $this->assertNull($quote->getConvertedAt());
        $this->assertNull($quote->getAbandonedAt());
    }

    public function testTimestampsUseTorontoTimezone(): void
    {
        $quote = new Quote();

        $this->assertSame('America/Toronto', $quote->getCreatedAt()->getTimezone()->getName());
    }

    public function testMarkReadyStampsReadyAtOnceAndIsStable(): void
    {
        $quote = new Quote();
        $quote->markReady();

        $this->assertSame(Quote::STATUS_READY, $quote->getStatus());
        $firstReadyAt = $quote->getReadyAt();
        $this->assertNotNull($firstReadyAt);

        // Re-confirming ready keeps the original stamp (idempotent gate).
        $quote->markDraft()->markReady();
        $this->assertSame($firstReadyAt, $quote->getReadyAt());
    }

    public function testMarkConvertedRecordsOrderAndStampsOnce(): void
    {
        $quote = new Quote();
        $quote->markReady();

        $quote->markConverted(42);

        $this->assertSame(Quote::STATUS_CONVERTED, $quote->getStatus());
        $this->assertTrue($quote->isConverted());
        $this->assertSame(42, $quote->getConvertedOrderId());
        $convertedAt = $quote->getConvertedAt();
        $this->assertNotNull($convertedAt);

        // A duplicate conversion (e.g. webhook racing the browser return) must
        // not re-stamp the moment of conversion.
        $quote->markConverted(42);
        $this->assertSame($convertedAt, $quote->getConvertedAt());
    }

    public function testMarkAbandonedStampsOnce(): void
    {
        $quote = new Quote();

        $quote->markAbandoned();

        $this->assertSame(Quote::STATUS_ABANDONED, $quote->getStatus());
        $abandonedAt = $quote->getAbandonedAt();
        $this->assertNotNull($abandonedAt);

        $quote->markAbandoned();
        $this->assertSame($abandonedAt, $quote->getAbandonedAt());
    }

    public function testRecalculateTaxTotalSumsLines(): void
    {
        $quote = new Quote();
        $quote->addTaxLine(new QuoteTaxLine('gst', 'GST', '0.05000', '5.00'));
        $quote->addTaxLine(new QuoteTaxLine('qst', 'QST', '0.09975', '9.98'));

        $quote->recalculateTaxTotal();

        $this->assertSame('14.98', $quote->getTaxTotal());
    }

    public function testAddTaxLineWiresBackReference(): void
    {
        $quote = new Quote();
        $line = new QuoteTaxLine('hst', 'HST', '0.13000', '13.00');

        $quote->addTaxLine($line);

        $this->assertSame($quote, $line->getQuote());
        $this->assertCount(1, $quote->getTaxLines());
    }

    public function testPaymentLinkageIsProviderNeutral(): void
    {
        $quote = new Quote();
        $this->assertFalse($quote->hasPayment());
        $this->assertNull($quote->getPaymentProvider());
        $this->assertNull($quote->getPaymentReference());

        // Any provider's opaque reference fits the same two neutral fields.
        $quote->setPayment('stripe', 'pi_123');
        $this->assertTrue($quote->hasPayment());
        $this->assertSame('stripe', $quote->getPaymentProvider());
        $this->assertSame('pi_123', $quote->getPaymentReference());

        $quote->setPayment('paypal', 'PAYID-ABC');
        $this->assertSame('paypal', $quote->getPaymentProvider());
        $this->assertSame('PAYID-ABC', $quote->getPaymentReference());

        $quote->clearPayment();
        $this->assertFalse($quote->hasPayment());
    }

    public function testCurrencyIsUppercased(): void
    {
        $quote = new Quote();
        $quote->setCurrency('usd');

        $this->assertSame('USD', $quote->getCurrency());
    }

    public function testShippingCountryDefaultsToCaWhenBlank(): void
    {
        $quote = new Quote();
        $quote->setShippingCountry('');

        $this->assertSame('CA', $quote->getShippingCountry());
    }
}
