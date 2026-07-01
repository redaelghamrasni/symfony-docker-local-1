<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\CartRepository;
use App\Entity\CartItem;

#[ORM\Entity(repositoryClass: CartRepository::class)]
class Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $items;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private $total = 0.00;

    /**
     * Summary of __construct
     */
    public function __construct()
    {
        $tz = new \DateTimeZone('America/Toronto');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function addItem(CartItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
            $this->recalculateTotal();
        }
        return $this;
    }

    public function removeItem(CartItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getCart() === $this) {
                $item->setCart(null);
            }
            $this->recalculateTotal();
    }
    return $this;
    }

    public function recalculateTotal(): void
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getSubtotal();
        }
        $this->total = number_format($total, 2, '.', '');
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;
        return $this;
    }

    public function clear(): self
    {
         $this->items->clear();
        $this->total = '0.00';
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item->getQuantity();
        }
        return $count;
    }

    public function getItems()
    {
        return $this->items;
    }
}
