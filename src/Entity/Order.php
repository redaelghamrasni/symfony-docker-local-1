<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, in_progress, shipped, completed

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $total = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $items;

    // Customer information at time of order
    #[ORM\Column(length: 100)]
    private ?string $customerFirstName = null;

    #[ORM\Column(length: 100)]
    private ?string $customerLastName = null;

    #[ORM\Column(length: 180)]
    private ?string $customerEmail = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $customerPhone = null;

    // Shipping address
    #[ORM\Column(length: 255)]
    private ?string $shippingStreet = null;

    #[ORM\Column(length: 100)]
    private ?string $shippingCity = null;

    #[ORM\Column(length: 20)]
    private ?string $shippingPostalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingProvince = null;

    // Billing address
    #[ORM\Column(length: 255)]
    private ?string $billingStreet = null;

    #[ORM\Column(length: 100)]
    private ?string $billingCity = null;

    #[ORM\Column(length: 20)]
    private ?string $billingPostalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $billingProvince = null;

    // Payment info (captured from Stripe on checkout)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paymentMethod = null; // card, paypal, link, etc.

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paymentBrand = null; // visa, mastercard, amex, etc.

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $paymentLast4 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    // Shipping tracking (set manually by admin)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $shippingCarrierStatus = null; // pending, shipped, in_transit, delivered, failed

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingCarrier = null;

    #[ORM\Column(name: 'shipping_date', nullable: true)]
    private ?\DateTimeImmutable $shippingDate = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(name: 'estimated_delivery_date', nullable: true)]
    private ?\DateTimeImmutable $estimatedDeliveryDate = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $shippingLabelUrl = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $tz = new \DateTimeZone('America/Toronto');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->updatedAt = new \DateTimeImmutable('now', $tz);
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $tz = new \DateTimeZone('America/Toronto');
        $this->updatedAt = new \DateTimeImmutable('now', $tz);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
        return $this;
    }

    public function removeItem(OrderItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getOrder() === $this) {
                $item->setOrder(null);
            }
        }
        return $this;
    }

    public function getCustomerFirstName(): ?string
    {
        return $this->customerFirstName;
    }

    public function setCustomerFirstName(string $customerFirstName): self
    {
        $this->customerFirstName = $customerFirstName;
        return $this;
    }

    public function getCustomerLastName(): ?string
    {
        return $this->customerLastName;
    }

    public function setCustomerLastName(string $customerLastName): self
    {
        $this->customerLastName = $customerLastName;
        return $this;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function setCustomerEmail(string $customerEmail): self
    {
        $this->customerEmail = $customerEmail;
        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): self
    {
        $this->customerPhone = $customerPhone;
        return $this;
    }

    public function getShippingStreet(): ?string
    {
        return $this->shippingStreet;
    }

    public function setShippingStreet(string $shippingStreet): self
    {
        $this->shippingStreet = $shippingStreet;
        return $this;
    }

    public function getShippingCity(): ?string
    {
        return $this->shippingCity;
    }

    public function setShippingCity(string $shippingCity): self
    {
        $this->shippingCity = $shippingCity;
        return $this;
    }

    public function getShippingPostalCode(): ?string
    {
        return $this->shippingPostalCode;
    }

    public function setShippingPostalCode(string $shippingPostalCode): self
    {
        $this->shippingPostalCode = $shippingPostalCode;
        return $this;
    }

    public function getShippingProvince(): ?string { return $this->shippingProvince; }
    public function setShippingProvince(?string $v): self { $this->shippingProvince = $v; return $this; }

    public function getBillingStreet(): ?string
    {
        return $this->billingStreet;
    }

    public function setBillingStreet(string $billingStreet): self
    {
        $this->billingStreet = $billingStreet;
        return $this;
    }

    public function getBillingCity(): ?string
    {
        return $this->billingCity;
    }

    public function setBillingCity(string $billingCity): self
    {
        $this->billingCity = $billingCity;
        return $this;
    }

    public function getBillingPostalCode(): ?string
    {
        return $this->billingPostalCode;
    }

    public function setBillingPostalCode(string $billingPostalCode): self
    {
        $this->billingPostalCode = $billingPostalCode;
        return $this;
    }

    public function getBillingProvince(): ?string { return $this->billingProvince; }
    public function setBillingProvince(?string $v): self { $this->billingProvince = $v; return $this; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $v): self { $this->paymentMethod = $v; return $this; }

    public function getPaymentBrand(): ?string { return $this->paymentBrand; }
    public function setPaymentBrand(?string $v): self { $this->paymentBrand = $v; return $this; }

    public function getPaymentLast4(): ?string { return $this->paymentLast4; }
    public function setPaymentLast4(?string $v): self { $this->paymentLast4 = $v; return $this; }

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $v): self { $this->stripePaymentIntentId = $v; return $this; }

    public function getShippingCarrierStatus(): ?string { return $this->shippingCarrierStatus; }
    public function setShippingCarrierStatus(?string $v): self { $this->shippingCarrierStatus = $v; return $this; }

    public function getShippingCarrier(): ?string { return $this->shippingCarrier; }
    public function setShippingCarrier(?string $v): self { $this->shippingCarrier = $v; return $this; }

    public function getShippingDate(): ?\DateTimeImmutable { return $this->shippingDate; }
    public function setShippingDate(?\DateTimeImmutable $v): self { $this->shippingDate = $v; return $this; }

    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(?string $v): self { $this->trackingNumber = $v; return $this; }

    public function getEstimatedDeliveryDate(): ?\DateTimeImmutable { return $this->estimatedDeliveryDate; }
    public function setEstimatedDeliveryDate(?\DateTimeImmutable $v): self { $this->estimatedDeliveryDate = $v; return $this; }

    public function getShippingLabelUrl(): ?string { return $this->shippingLabelUrl; }
    public function setShippingLabelUrl(?string $v): self { $this->shippingLabelUrl = $v; return $this; }
}
