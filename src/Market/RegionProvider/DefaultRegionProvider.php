<?php

namespace App\Market\RegionProvider;

use App\Market\Region;

/**
 * Regions from the app's own bundled data file (config/market/regions.php).
 *
 * Public-domain ISO 3166-2 data shipped with the code — no external service,
 * no geographical dependency. This is the default layer the DatabaseRegion
 * provider overrides.
 */
final class DefaultRegionProvider implements RegionProviderInterface
{
    /** @var array<string, array<string, string>>|null */
    private ?array $data = null;

    public function __construct(private readonly string $dataFile)
    {
    }

    public function regions(string $country): array
    {
        $country = strtoupper($country);
        $regions = [];

        foreach ($this->data()[$country] ?? [] as $code => $name) {
            $regions[] = new Region($code, $name);
        }

        return $regions;
    }

    /** @return array<string, array<string, string>> */
    private function data(): array
    {
        if ($this->data === null) {
            $loaded = is_file($this->dataFile) ? require $this->dataFile : [];
            $this->data = is_array($loaded) ? $loaded : [];
        }

        return $this->data;
    }
}
