<?php

namespace App\Entity;

use App\Repository\MarketRegionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin-managed sub-national region, overriding the built-in defaults.
 *
 * The market_region table is EMPTY by default — the bundled data file covers
 * shipped markets, so nobody retypes known geography. A merchant adds rows here
 * only to support a country not in the defaults, or to customise one: if any
 * row exists for a country, that set becomes authoritative for it (see
 * RegionCatalog). Managed at /admin/regions.
 */
#[ORM\Entity(repositoryClass: MarketRegionRepository::class)]
#[ORM\Table(name: 'market_region')]
#[ORM\UniqueConstraint(name: 'uniq_country_code', columns: ['country', 'code'])]
class MarketRegion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 3166-1 alpha-2, uppercase. */
    #[ORM\Column(length: 2)]
    private string $country = '';

    /** Stable region key, stored on the order and shared with tax rates. */
    #[ORM\Column(length: 10)]
    private string $code = '';

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $country): self { $this->country = strtoupper(trim($country)); return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
