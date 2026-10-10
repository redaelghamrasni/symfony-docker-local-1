<?php

namespace App\Entity;

use App\Repository\QuoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The mutable pre-payment object: everything a checkout gathers before a payment
 * succeeds. Deliberately a SEPARATE entity from Order — Order is an immutable
 * snapshot frozen at order time (see CLAUDE.md "Sensitive areas"), so the
 * mutable, still-changing pre-payment data must not live in the `order` table
 * (it would pollute order numbering and break that immutability). This mirrors
 * the classic split: mutable `quote` ↔ immutable `sales_order`.
 *
 * A Quote always exists before a payment is attempted, which is what makes the
 * Stripe webhook correct: on payment_intent.succeeded the webhook can find the
 * quote (by PI id or PI metadata) and convert it into an Order, even when the
 * browser never returns. See docs/quote-lifecycle-plan.md.
 *
 * Lifecycle: draft → ready (shipping serviceable) → converted (payment succeeded,
 * Order created) · or → abandoned (unpaid past threshold, soft-marked only after
 * re-checking Stripe).
 */
#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'quote')]
// A given payment reference backs at most one quote: the DB-level idempotency
// anchor the converter relies on when a payment settles, whatever the provider.
// Kept provider-neutral on purpose — (payment_provider, payment_reference), not
// a Stripe-specific column — so no concrete payment implementation leaks into
// the quote schema. MySQL treats NULLs as distinct under a unique index, so
// quotes with no payment reference yet never collide.
#[ORM\UniqueConstraint(name: 'UNIQ_QUOTE_PAYMENT', columns: ['payment_provider', 'payment_reference'])]
#[ORM\HasLifecycleCallbacks]
class Quote
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_ABANDONED = 'abandoned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Nullable for guests: a quote can exist for an anonymous checkout session.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Session that owns this quote while it is still a guest draft. Lets the
     * checkout reattach the same quote across requests and lets the purge
     * command key guest quotes by session (see plan, open decision #2).
     */
    #[ORM\Column(name: 'session_id', length: 128, nullable: true)]
    private ?string $sessionId = null;

    // --- Amounts (snapshot, like Order) ---
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $total = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $subtotal = null;

    #[ORM\Column(name: 'shipping_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $shippingAmount = null;

    /**
     * Total tax, a convenience snapshot so the quote knows its tax without
     * loading the lines. The per-component breakdown lives in taxLines.
     */
    #[ORM\Column(name: 'tax_total', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $taxTotal = '0.00';

    /** @var Collection<int, QuoteTaxLine> the tax breakdown, one row per component */
    #[ORM\OneToMany(targetEntity: QuoteTaxLine::class, mappedBy: 'quote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $taxLines;

    #[ORM\Column(length: 3, options: ['default' => 'CAD'])]
    private string $currency = 'CAD';

    // --- Chosen shipping method (Shippo rate snapshot) ---
    #[ORM\Column(name: 'shipping_method_carrier', length: 100, nullable: true)]
    private ?string $shippingMethodCarrier = null;

    #[ORM\Column(name: 'shipping_method_name', length: 150, nullable: true)]
    private ?string $shippingMethodName = null;

    #[ORM\Column(name: 'shipping_method_reference', length: 100, nullable: true)]
    private ?string $shippingMethodReference = null;

    /** @var Collection<int, QuoteItem> the cart lines, snapshot at quote time */
    #[ORM\OneToMany(targetEntity: QuoteItem::class, mappedBy: 'quote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    // --- Customer (nullable: filled as checkout progresses) ---
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customerFirstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customerLastName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $customerEmail = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $customerPhone = null;

    // --- Shipping address ---
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shippingStreet = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingCity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $shippingPostalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingProvince = null;

    #[ORM\Column(length: 2, options: ['default' => 'CA'])]
    private string $shippingCountry = 'CA';

    // --- Billing address ---
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingStreet = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $billingCity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $billingPostalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $billingProvince = null;

    // --- Payment linkage (provider-neutral) ---
    // The quote records only WHICH provider is settling it and that provider's
    // own opaque reference (a Stripe PaymentIntent id, a PayPal order id, …),
    // never a provider-specific column. This mirrors PaymentOutcome: the active
    // market's payment adapter owns the concrete shape, the quote stays agnostic.
    #[ORM\Column(name: 'payment_provider', length: 30, nullable: true)]
    private ?string $paymentProvider = null;

    #[ORM\Column(name: 'payment_reference', length: 191, nullable: true)]
    private ?string $paymentReference = null;

    /**
     * The id of the Order this quote converted into, once converted. Not an FK:
     * the converter is idempotent and this is a one-way pointer used to return
     * the existing order on a duplicate conversion attempt.
     */
    #[ORM\Column(name: 'converted_order_id', nullable: true)]
    private ?int $convertedOrderId = null;

    // --- Timestamps (America/Toronto, per CLAUDE.md) ---
    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'ready_at', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(name: 'converted_at', nullable: true)]
    private ?\DateTimeImmutable $convertedAt = null;

    #[ORM\Column(name: 'abandoned_at', nullable: true)]
    private ?\DateTimeImmutable $abandonedAt = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->taxLines = new ArrayCollection();
        $tz = new \DateTimeZone('America/Toronto');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->updatedAt = new \DateTimeImmutable('now', $tz);
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getStatus(): string { return $this->status; }

    public function isConverted(): bool { return $this->status === self::STATUS_CONVERTED; }

    /**
     * Marks the quote ready to pay — only valid once shipping is serviceable.
     * Stamps readyAt the first time; a re-confirmation keeps the original.
     */
    public function markReady(): self
    {
        $this->status = self::STATUS_READY;
        $this->readyAt ??= self::now();
        return $this;
    }

    /** Records the converting Order and stamps convertedAt. Idempotent-friendly. */
    public function markConverted(int $orderId): self
    {
        $this->status = self::STATUS_CONVERTED;
        $this->convertedOrderId = $orderId;
        $this->convertedAt ??= self::now();
        return $this;
    }

    public function markAbandoned(): self
    {
        $this->status = self::STATUS_ABANDONED;
        $this->abandonedAt ??= self::now();
        return $this;
    }

    /** Back to draft, e.g. when a cart edit invalidates a prior ready state. */
    public function markDraft(): self
    {
        $this->status = self::STATUS_DRAFT;
        return $this;
    }

    public function getSessionId(): ?string { return $this->sessionId; }
    public function setSessionId(?string $v): self { $this->sessionId = $v; return $this; }

    public function getTotal(): ?string { return $this->total; }
    public function setTotal(?string $v): self { $this->total = $v; return $this; }

    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $v): self { $this->subtotal = $v; return $this; }

    public function getShippingAmount(): ?string { return $this->shippingAmount; }
    public function setShippingAmount(?string $v): self { $this->shippingAmount = $v; return $this; }

    public function getTaxTotal(): string { return $this->taxTotal; }
    public function setTaxTotal(string $v): self { $this->taxTotal = $v; return $this; }

    /** @return Collection<int, QuoteTaxLine> */
    public function getTaxLines(): Collection { return $this->taxLines; }

    public function addTaxLine(QuoteTaxLine $line): self
    {
        if (!$this->taxLines->contains($line)) {
            $this->taxLines->add($line);
            $line->setQuote($this);
        }

        return $this;
    }

    public function removeTaxLine(QuoteTaxLine $line): self
    {
        if ($this->taxLines->removeElement($line) && $line->getQuote() === $this) {
            $line->setQuote(null);
        }

        return $this;
    }

    /** Sums the lines into taxTotal — call after building the breakdown. */
    public function recalculateTaxTotal(): self
    {
        $total = 0.0;
        foreach ($this->taxLines as $line) {
            $total += (float) $line->getAmount();
        }
        $this->taxTotal = number_format($total, 2, '.', '');

        return $this;
    }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): self { $this->currency = strtoupper($v); return $this; }

    public function getShippingMethodCarrier(): ?string { return $this->shippingMethodCarrier; }
    public function setShippingMethodCarrier(?string $v): self { $this->shippingMethodCarrier = $v; return $this; }

    public function getShippingMethodName(): ?string { return $this->shippingMethodName; }
    public function setShippingMethodName(?string $v): self { $this->shippingMethodName = $v; return $this; }

    public function getShippingMethodReference(): ?string { return $this->shippingMethodReference; }
    public function setShippingMethodReference(?string $v): self { $this->shippingMethodReference = $v; return $this; }

    /** @return Collection<int, QuoteItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(QuoteItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setQuote($this);
        }

        return $this;
    }

    public function removeItem(QuoteItem $item): self
    {
        if ($this->items->removeElement($item) && $item->getQuote() === $this) {
            $item->setQuote(null);
        }

        return $this;
    }

    public function getCustomerFirstName(): ?string { return $this->customerFirstName; }
    public function setCustomerFirstName(?string $v): self { $this->customerFirstName = $v; return $this; }

    public function getCustomerLastName(): ?string { return $this->customerLastName; }
    public function setCustomerLastName(?string $v): self { $this->customerLastName = $v; return $this; }

    public function getCustomerEmail(): ?string { return $this->customerEmail; }
    public function setCustomerEmail(?string $v): self { $this->customerEmail = $v; return $this; }

    public function getCustomerPhone(): ?string { return $this->customerPhone; }
    public function setCustomerPhone(?string $v): self { $this->customerPhone = $v; return $this; }

    public function getShippingStreet(): ?string { return $this->shippingStreet; }
    public function setShippingStreet(?string $v): self { $this->shippingStreet = $v; return $this; }

    public function getShippingCity(): ?string { return $this->shippingCity; }
    public function setShippingCity(?string $v): self { $this->shippingCity = $v; return $this; }

    public function getShippingPostalCode(): ?string { return $this->shippingPostalCode; }
    public function setShippingPostalCode(?string $v): self { $this->shippingPostalCode = $v; return $this; }

    public function getShippingProvince(): ?string { return $this->shippingProvince; }
    public function setShippingProvince(?string $v): self { $this->shippingProvince = $v; return $this; }

    public function getShippingCountry(): string { return $this->shippingCountry; }
    public function setShippingCountry(?string $v): self { $this->shippingCountry = strtoupper($v ?: 'CA'); return $this; }

    public function getBillingStreet(): ?string { return $this->billingStreet; }
    public function setBillingStreet(?string $v): self { $this->billingStreet = $v; return $this; }

    public function getBillingCity(): ?string { return $this->billingCity; }
    public function setBillingCity(?string $v): self { $this->billingCity = $v; return $this; }

    public function getBillingPostalCode(): ?string { return $this->billingPostalCode; }
    public function setBillingPostalCode(?string $v): self { $this->billingPostalCode = $v; return $this; }

    public function getBillingProvince(): ?string { return $this->billingProvince; }
    public function setBillingProvince(?string $v): self { $this->billingProvince = $v; return $this; }

    public function getPaymentProvider(): ?string { return $this->paymentProvider; }
    public function getPaymentReference(): ?string { return $this->paymentReference; }

    /**
     * Links this quote to the payment the active market's provider is settling.
     * Both parts are set together: the (provider, reference) pair is the unique
     * idempotency key the converter looks the quote up by, whatever the provider.
     */
    public function setPayment(string $provider, string $reference): self
    {
        $this->paymentProvider  = $provider;
        $this->paymentReference = $reference;
        return $this;
    }

    public function clearPayment(): self
    {
        $this->paymentProvider  = null;
        $this->paymentReference = null;
        return $this;
    }

    public function hasPayment(): bool
    {
        return $this->paymentProvider !== null && $this->paymentReference !== null;
    }

    public function getConvertedOrderId(): ?int { return $this->convertedOrderId; }
    public function setConvertedOrderId(?int $v): self { $this->convertedOrderId = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }

    public function getReadyAt(): ?\DateTimeImmutable { return $this->readyAt; }
    public function getConvertedAt(): ?\DateTimeImmutable { return $this->convertedAt; }
    public function getAbandonedAt(): ?\DateTimeImmutable { return $this->abandonedAt; }
}
