<?php

namespace App\Tests\Unit\Market\Tax;

use App\Market\Tax\Engine\CanadaTaxEngine;
use App\Repository\TaxRateRepository;
use App\Service\TaxService;
use PHPUnit\Framework\TestCase;

class CanadaTaxEngineTest extends TestCase
{
    private CanadaTaxEngine $engine;

    protected function setUp(): void
    {
        // Empty repository → TaxService uses its statutory fallback rates.
        $repository = $this->createMock(TaxRateRepository::class);
        $repository->method('findAllOrdered')->willReturn([]);

        $this->engine = new CanadaTaxEngine(new TaxService($repository));
    }

    public function testSupportsOnlyCanada(): void
    {
        $this->assertTrue($this->engine->supports('CA'));
        $this->assertTrue($this->engine->supports('ca'));
        $this->assertFalse($this->engine->supports('FR'));
        $this->assertFalse($this->engine->supports('US'));
    }

    public function testQuebecQuotesGstAndQst(): void
    {
        $quote = $this->engine->quote(100.0, 'CA', 'QC');

        $this->assertCount(2, $quote->lines);
        $this->assertSame('gst', $quote->lines[0]->code);
        $this->assertSame('5.00', $quote->lines[0]->amount);
        $this->assertSame('qst', $quote->lines[1]->code);
        $this->assertSame('QST', $quote->lines[1]->label);
        $this->assertSame('9.98', $quote->lines[1]->amount); // round(100 * 0.09975, 2)
        $this->assertSame('14.98', $quote->total());
    }

    public function testOntarioQuotesSingleHstLine(): void
    {
        $quote = $this->engine->quote(100.0, 'CA', 'ON');

        $this->assertCount(1, $quote->lines);
        $this->assertSame('hst', $quote->lines[0]->code);
        $this->assertSame('13.00', $quote->lines[0]->amount);
        $this->assertSame('13.00', $quote->total());
    }

    public function testExportIsZeroRated(): void
    {
        $quote = $this->engine->quote(100.0, 'US', 'NY');

        $this->assertTrue($quote->isEmpty());
        $this->assertSame('0.00', $quote->total());
    }
}
