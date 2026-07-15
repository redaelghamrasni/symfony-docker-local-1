<?php

namespace App\Tests\Unit\Service;

use App\Service\TaxService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxServiceTest extends TestCase
{
    private TaxService $taxService;

    protected function setUp(): void
    {
        $this->taxService = new TaxService();
    }

    #[DataProvider('provinceProvider')]
    public function testTaxRatesForProvince(
        string $province,
        float $expectedGst,
        float $expectedPst,
        float $expectedHst
    ): void {
        $rates = $this->taxService->getRateForProvince($province);

        $this->assertEquals($expectedGst, $rates['gst'], "GST incorrecte pour {$province}");
        $this->assertEquals($expectedPst, $rates['pst'], "PST incorrecte pour {$province}");
        $this->assertEquals($expectedHst, $rates['hst'], "HST incorrecte pour {$province}");
    }

    public static function provinceProvider(): array
    {
        return [
            'Québec'               => ['QC', 0.05, 0.09975, 0.00],
            'Ontario'              => ['ON', 0.00, 0.00,    0.13],
            'Alberta'              => ['AB', 0.05, 0.00,    0.00],
            'Colombie-Britannique' => ['BC', 0.05, 0.07,    0.00],
            'Nouvelle-Écosse'      => ['NS', 0.00, 0.00,    0.14],
        ];
    }

    public function testTaxCalculationForQuebec(): void
{
        $result = $this->taxService->calculateTax(100.00, 'QC');

        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(5.00,   $result['gst_amount']);
        $this->assertEquals(9.98,   $result['pst_amount']); // round(100 * 0.09975, 2)
        $this->assertEquals(0.00,   $result['hst_amount']);
        $this->assertEquals(14.98,  $result['tax_total']);   // 5.00 + 9.98
        $this->assertEquals(114.98, $result['grand_total']); // 100 + 14.98
    }

    public function testTaxCalculationForOntario(): void
    {
        $result = $this->taxService->calculateTax(100.00, 'ON');

        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(0.00,   $result['gst_amount']);
        $this->assertEquals(13.00,  $result['hst_amount']);
        $this->assertEquals(113.00, $result['grand_total']);
    }

    public function testTaxCalculationForAlberta(): void
    {
        $result = $this->taxService->calculateTax(100.00, 'AB');

        $this->assertEquals(5.00,   $result['gst_amount']);
        $this->assertEquals(0.00,   $result['pst_amount']);
        $this->assertEquals(0.00,   $result['hst_amount']);
        $this->assertEquals(105.00, $result['grand_total']);
    }

    public function testUnknownProvinceDefaultsToGstOnly(): void
    {
        $result = $this->taxService->calculateTax(100.00, 'XX');

        $this->assertEquals(5.00,   $result['gst_amount']);
        $this->assertEquals(105.00, $result['grand_total']);
    }
}