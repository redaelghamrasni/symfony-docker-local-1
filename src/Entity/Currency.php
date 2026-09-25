<?php

namespace App\Entity;

use App\Repository\CurrencyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A currency the shop can price and charge in.
 *
 * Deliberately knows nothing about countries. A currency is not a market:
 * shipping feasibility, stock location and tax rules belong to a future
 * country/region concept that will *reference* a currency, not live inside it.
 * The only place that decides which currency applies to a visitor is
 * App\Service\CurrencyService, so that future mapping has one seam to plug
 * into rather than a rule spread across the checkout.
 */
#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currency')]
#[ORM\HasLifecycleCallbacks]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 4217, uppercase — CAD, USD, EUR. */
    #[ORM\Column(length: 3, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(length: 8)]
    private string $symbol = '$';

    /** Where the symbol sits relative to the amount: 12,00 € vs $12.00 */
    #[ORM\Column(length: 6, options: ['default' => 'before'])]
    private string $symbolPosition = 'before';

    /**
     * How many units of this currency one unit of the default currency buys.
     * The default currency is always 1.0.
     *
     * Used only where no explicit per-article price exists: an
     * App\Entity\ArticlePrice row always wins over a converted amount.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 6, options: ['default' => '1.000000'])]
    private string $exchangeRate = '1.000000';

    /** Offered to visitors in the front-office currency selector. */
    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    /**
     * The currency prices are authored in and rates are expressed against.
     * Exactly one currency carries this flag; CurrencyRepository enforces it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Timezone is fixed project-wide (see CLAUDE.md); the class carries
    // HasLifecycleCallbacks so these actually run.
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

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }

    public function getSymbolPosition(): string { return $this->symbolPosition; }
    public function setSymbolPosition(string $p): self { $this->symbolPosition = $p === 'after' ? 'after' : 'before'; return $this; }

    public function getExchangeRate(): string { return $this->exchangeRate; }
    public function setExchangeRate(string|float $rate): self { $this->exchangeRate = (string) $rate; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): self { $this->isDefault = $isDefault; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** "CAD — Canadian dollar" for admin dropdowns. */
    public function getLabel(): string
    {
        return $this->code . ' — ' . $this->name;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
