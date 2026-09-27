<?php

namespace App\Entity;

use App\Repository\TaxRateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sales-tax rates for one province, editable in the back office.
 *
 * Replaces the rates that were hardcoded in two places (CheckoutController and
 * TaxService), which had already drifted apart. Rates change by government
 * decision, not by code release, so they belong in data — a fresh row or an
 * edit here changes what checkout charges without a deploy.
 *
 * Rates are stored as decimal fractions (0.05 = 5%, 0.09975 = Quebec's QST),
 * with five decimal places so QST fits exactly.
 */
#[ORM\Entity(repositoryClass: TaxRateRepository::class)]
#[ORM\Table(name: 'tax_rate')]
#[ORM\HasLifecycleCallbacks]
class TaxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Province/territory code, uppercase — QC, ON, NS. */
    #[ORM\Column(length: 5, unique: true)]
    private string $province = '';

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 5, options: ['default' => '0.00000'])]
    private string $gst = '0.00000';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 5, options: ['default' => '0.00000'])]
    private string $pst = '0.00000';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 5, options: ['default' => '0.00000'])]
    private string $hst = '0.00000';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));
    }

    public function getId(): ?int { return $this->id; }

    public function getProvince(): string { return $this->province; }
    public function setProvince(string $province): self { $this->province = strtoupper(trim($province)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getGst(): string { return $this->gst; }
    public function setGst(string|float $gst): self { $this->gst = (string) $gst; return $this; }

    public function getPst(): string { return $this->pst; }
    public function setPst(string|float $pst): self { $this->pst = (string) $pst; return $this; }

    public function getHst(): string { return $this->hst; }
    public function setHst(string|float $hst): self { $this->hst = (string) $hst; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** Combined rate applied to an order — the three are mutually exclusive in practice. */
    public function getCombinedRate(): float
    {
        return (float) $this->gst + (float) $this->pst + (float) $this->hst;
    }

    /** "GST + PST" provinces vs "HST" provinces, for display. */
    public function isHstProvince(): bool
    {
        return (float) $this->hst > 0;
    }

    public function __toString(): string
    {
        return $this->province;
    }
}
