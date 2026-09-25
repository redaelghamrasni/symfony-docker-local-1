<?php

namespace App\Entity;

use App\Repository\ArticlePriceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An explicit price for one article in one currency.
 *
 * Follows the same pivot-entity shape as ArticleTranslation: the article keeps
 * its price in the default currency, and a row here overrides the converted
 * amount for a specific currency.
 *
 * Why an override rather than only conversion: an exchange rate gives
 * CA$377.99 -> US$272.15, which no shop would actually charge. Being able to
 * set $279.99 by hand is the point; conversion is the fallback that keeps a
 * newly enabled currency usable before every article has been priced.
 */
#[ORM\Entity(repositoryClass: ArticlePriceRepository::class)]
#[ORM\Table(name: 'article_price')]
#[ORM\UniqueConstraint(name: 'uniq_article_currency', columns: ['article_id', 'currency_id'])]
#[ORM\HasLifecycleCallbacks]
class ArticlePrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Currency $currency = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price = '0.00';

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

    public function getArticle(): ?Article { return $this->article; }
    public function setArticle(?Article $article): self { $this->article = $article; return $this; }

    public function getCurrency(): ?Currency { return $this->currency; }
    public function setCurrency(?Currency $currency): self { $this->currency = $currency; return $this; }

    public function getPrice(): string { return $this->price; }
    public function setPrice(string|float $price): self { $this->price = (string) $price; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
