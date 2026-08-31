<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\User;

/**
 * Builds Meilisearch documents for a single entity and pushes/removes them.
 * The field mappings here are the single source of truth, also reused by
 * MeilisearchReindexCommand for the bulk reindex, so the two never drift apart.
 */
class SearchIndexService
{
    public function __construct(
        private readonly MeilisearchService $meilisearch,
    ) {
    }

    public function indexArticle(Article $article): void
    {
        $this->meilisearch->index('articles', [$this->toArticleDocument($article)]);
    }

    public function removeArticle(int $id): void
    {
        $this->meilisearch->removeDocument('articles', $id);
    }

    public function indexCategory(Category $category): void
    {
        $this->meilisearch->index('categories', [$this->toCategoryDocument($category)]);
    }

    public function removeCategory(int $id): void
    {
        $this->meilisearch->removeDocument('categories', $id);
    }

    public function indexUser(User $user): void
    {
        $this->meilisearch->index('users', [$this->toUserDocument($user)]);
    }

    public function removeUser(int $id): void
    {
        $this->meilisearch->removeDocument('users', $id);
    }

    public function indexOrder(Order $order): void
    {
        $this->meilisearch->index('orders', [$this->toOrderDocument($order)]);
    }

    public function removeOrder(int $id): void
    {
        $this->meilisearch->removeDocument('orders', $id);
    }

    public function toArticleDocument(Article $article): array
    {
        return [
            'id'        => $article->getId(),
            'title'     => $article->getTitle(),
            'content'   => $article->getContent(),
            'price'     => $article->getPrice(),
            'imageUrl'  => $article->getImageUrl(),
            'createdAt' => $article->getCreatedAt()?->getTimestamp(),
        ];
    }

    public function toCategoryDocument(Category $category): array
    {
        return [
            'id'        => $category->getId(),
            'name'      => $category->getName(),
            'slug'      => $category->getSlug(),
            'createdAt' => $category->getCreatedAt()?->getTimestamp(),
        ];
    }

    public function toUserDocument(User $user): array
    {
        return [
            'id'        => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName'  => $user->getLastName(),
            'email'     => $user->getEmail(),
            'createdAt' => $user->getCreatedAt()?->getTimestamp(),
        ];
    }

    public function toOrderDocument(Order $order): array
    {
        return [
            'id'                => $order->getId(),
            'customerFirstName' => $order->getCustomerFirstName(),
            'customerLastName'  => $order->getCustomerLastName(),
            'customerEmail'     => $order->getCustomerEmail(),
            'status'            => $order->getStatus(),
            'total'             => $order->getTotal(),
            'createdAt'         => $order->getCreatedAt()?->getTimestamp(),
        ];
    }
}
