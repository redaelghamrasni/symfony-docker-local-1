<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['article:read']],
    denormalizationContext: ['groups' => ['article:write']]
)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['article:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['article:read', 'article:write'])]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['article:read', 'article:write'])]
    private ?string $content = null;

    #[ORM\Column(name: 'created_at')]
    #[Groups(['article:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 20, unique: true, nullable: true)]
    #[Groups(['article:read'])]
    private ?string $sku = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price = '0.00';

    #[ORM\ManyToOne(inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\ManyToMany(targetEntity: Promotion::class, inversedBy: 'articles')]
    #[ORM\JoinTable(name: 'article_promotion')]
    private Collection $promotions;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\OneToMany(targetEntity: ArticleTranslation::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\OneToMany(targetEntity: ArticleImage::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    public function __construct()
    {
        $this->promotions = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    #[ORM\PrePersist]
    public function generateSku(): void
    {
        if ($this->sku === null) {
            $this->sku = 'ART-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        }
        if ($this->createdAt === null) {
            $tz = new \DateTimeZone('America/Toronto');
            $this->createdAt = new \DateTimeImmutable('now', $tz);
        }
    }

    public function getName(): ?string
    {
        return $this->title;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string|float|int $price): static
    {
        $this->price = (string) $price;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getPromotions(): Collection
    {
        return $this->promotions;
    }

    public function addPromotion(Promotion $promotion): static
    {
        if (!$this->promotions->contains($promotion)) {
            $this->promotions->add($promotion);
        }

        return $this;
    }

    public function removePromotion(Promotion $promotion): static
    {
        $this->promotions->removeElement($promotion);

        return $this;
    }

    public function getActivePromotion(): ?Promotion
    {
        foreach ($this->promotions as $promotion) {
            if ($promotion->isCurrentlyActive()) {
                return $promotion;
            }
        }
        return null;
    }

    public function hasActivePromotion(): bool
    {
        return $this->getActivePromotion() !== null;
    }

    public function getEffectivePrice(): float
    {
        $activePromotion = $this->getActivePromotion();
        $basePrice = (float) $this->price;

        if ($activePromotion === null) {
            return $basePrice;
        }

        $value = (float) $activePromotion->getValue();

        return match ($activePromotion->getType()) {
            Promotion::TYPE_PERCENT_OFF => $basePrice * (1 - $value / 100),
            Promotion::TYPE_AMOUNT_OFF  => max(0.0, $basePrice - $value),
            Promotion::TYPE_FIXED_PRICE => $value,
            default                     => $basePrice,
        };
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    // ── Gallery images ───────────────────────────────────────────────────────

    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ArticleImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setArticle($this);
        }
        return $this;
    }

    public function removeImage(ArticleImage $image): static
    {
        $this->images->removeElement($image);
        return $this;
    }

    // ── Translations ─────────────────────────────────────────────────────────

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?ArticleTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->getLocale() === $locale) {
                return $t;
            }
        }
        return null;
    }

    public function getOrCreateTranslation(string $locale): ArticleTranslation
    {
        $translation = $this->getTranslation($locale);
        if ($translation === null) {
            $translation = new ArticleTranslation();
            $translation->setLocale($locale)->setArticle($this);
            $this->translations->add($translation);
        }
        return $translation;
    }

    public function getTranslatedTitle(string $locale): string
    {
        $t = $this->getTranslation($locale);
        if ($t !== null && $t->getTitle() !== '') {
            return $t->getTitle();
        }
        // fallback to the other locale, then to base title
        foreach ($this->translations as $t2) {
            if ($t2->getTitle() !== '') {
                return $t2->getTitle();
            }
        }
        return $this->title ?? '';
    }

    public function getTranslatedContent(string $locale): string
    {
        $t = $this->getTranslation($locale);
        if ($t !== null && $t->getContent() !== '') {
            return $t->getContent();
        }
        foreach ($this->translations as $t2) {
            if ($t2->getContent() !== '') {
                return $t2->getContent();
            }
        }
        return $this->content ?? '';
    }
}
