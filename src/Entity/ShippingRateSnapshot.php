<?php

namespace App\Entity;

use App\Repository\ShippingRateSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The latest known-good set of live shipping rates for one route/parcel profile.
 *
 * Captured by `app:shipping:refresh-snapshots` (daily + on server startup) and
 * served by ShippingService as a degraded-mode fallback when the live Shippo
 * call returns no rates — real prices from the last successful rating, never
 * mock numbers. One row per route key (country + region + weight band, see
 * App\Shipping\Snapshot\ShippingRouteKey); the key is unique, so a refresh
 * upserts rather than piling up rows.
 */
#[ORM\Entity(repositoryClass: ShippingRateSnapshotRepository::class)]
#[ORM\Table(name: 'shipping_rate_snapshot')]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPPING_SNAPSHOT_ROUTE', columns: ['route_key'])]
#[ORM\HasLifecycleCallbacks]
class ShippingRateSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'route_key', length: 64)]
    private string $routeKey;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(name: 'weight_band', length: 16)]
    private string $weightBand;

    /** The normalized rate rows, exactly as ShippingService returns them. */
    #[ORM\Column(type: 'json')]
    private array $rates = [];

    #[ORM\Column(name: 'captured_at')]
    private ?\DateTimeImmutable $capturedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touchCapturedAt(): void
    {
        // Every refresh that writes this row stamps the capture time, in the
        // project's America/Toronto timezone like every other persisted date.
        $this->capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteKey(): string
    {
        return $this->routeKey;
    }

    public function setRouteKey(string $routeKey): static
    {
        $this->routeKey = $routeKey;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function getWeightBand(): string
    {
        return $this->weightBand;
    }

    public function setWeightBand(string $weightBand): static
    {
        $this->weightBand = $weightBand;
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getRates(): array
    {
        return $this->rates;
    }

    /** @param array<int, array<string, mixed>> $rates */
    public function setRates(array $rates): static
    {
        $this->rates = $rates;
        return $this;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
    }
}
