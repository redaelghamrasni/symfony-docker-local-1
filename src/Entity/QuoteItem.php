<?php

namespace App\Entity;

use App\Repository\QuoteItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A snapshot of one cart line on a Quote — the pre-payment counterpart of
 * OrderItem. The price and quantity are frozen onto the row so a later cart edit
 * (or price change) does not rewrite what the customer was quoted; on conversion
 * these become the OrderItem snapshot. A live FK to the Article is kept for
 * display, like OrderItem does.
 */
#[ORM\Entity(repositoryClass: QuoteItemRepository::class)]
#[ORM\Table(name: 'quote_item')]
class QuoteItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Article $article = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $subtotal = null;

    public function getId(): ?int { return $this->id; }

    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): self { $this->quote = $quote; return $this; }

    public function getArticle(): ?Article { return $this->article; }
    public function setArticle(?Article $article): self { $this->article = $article; return $this; }

    public function getQuantity(): ?int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): self { $this->unitPrice = $unitPrice; return $this; }

    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
}
