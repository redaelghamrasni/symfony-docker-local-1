<?php

namespace App\Entity;

use App\Repository\OrderTaxLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tax component of an order — a snapshot, like the prices and addresses
 * around it.
 *
 * Replaces the three fixed Canadian columns (tax_gst / tax_pst / tax_hst) that
 * hardcoded one country's tax model into the schema. A Canadian order now has
 * GST + QST lines; a German order one VAT line; a US order state + city lines;
 * a tax-free market none. The order stores whatever its market produced.
 *
 * `code` is a stable machine key for translating well-known taxes (gst → TPS in
 * fr-CA). `label` is the human text actually charged, used as the fallback when
 * a code has no translation (a future VAT line just shows "VAT"). `rate` is kept
 * for the receipt and may be null for legacy rows where only the amount was
 * recorded. Nothing here references the live tax_rate table — a later rate
 * change must not rewrite a past order.
 */
#[ORM\Entity(repositoryClass: OrderTaxLineRepository::class)]
#[ORM\Table(name: 'order_tax_line')]
class OrderTaxLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'taxLines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    /** Machine key for translation: gst, pst, qst, hst, vat, … */
    #[ORM\Column(length: 20)]
    private string $code = '';

    /** Human label actually charged, and the fallback when `code` has no translation. */
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

    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): self { $this->order = $order; return $this; }

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
