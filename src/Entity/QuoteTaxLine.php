<?php

namespace App\Entity;

use App\Repository\QuoteTaxLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tax component of a Quote — the pre-payment counterpart of OrderTaxLine,
 * same shape (code/label/rate/amount/jurisdiction) so conversion is a direct
 * copy. It is a snapshot of the active market's tax engine output at quote time;
 * nothing here references the live tax_rate table, so a later rate change never
 * rewrites what was quoted.
 */
#[ORM\Entity(repositoryClass: QuoteTaxLineRepository::class)]
#[ORM\Table(name: 'quote_tax_line')]
class QuoteTaxLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'taxLines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    /** Machine key for translation: gst, pst, qst, hst, vat, … */
    #[ORM\Column(length: 20)]
    private string $code = '';

    /** Human label actually quoted, and the fallback when `code` has no translation. */
    #[ORM\Column(length: 40)]
    private string $label = '';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 5, nullable: true)]
    private ?string $rate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount = '0.00';

    /** Taxing jurisdiction (province/state/country), for reporting and remittance. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $jurisdiction = null;

    public function __construct(
        string $code = '',
        string $label = '',
        ?string $rate = null,
        string $amount = '0.00',
        ?string $jurisdiction = null,
    ) {
        $this->code = $code;
        $this->label = $label;
        $this->rate = $rate;
        $this->amount = $amount;
        $this->jurisdiction = $jurisdiction;
    }

    public function getId(): ?int { return $this->id; }

    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): self { $this->quote = $quote; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getRate(): ?string { return $this->rate; }
    public function setRate(?string $rate): self { $this->rate = $rate; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    public function getJurisdiction(): ?string { return $this->jurisdiction; }
    public function setJurisdiction(?string $jurisdiction): self { $this->jurisdiction = $jurisdiction; return $this; }
}
