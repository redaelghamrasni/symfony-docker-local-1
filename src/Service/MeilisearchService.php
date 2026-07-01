<?php

namespace App\Service;

use App\Entity\Article;

class MeilisearchService
{
    private mixed $client;
    private string $indexName = 'articles';

    public function __construct(string $meilisearchUrl, string $apiKey)
    {
        if (!class_exists(\Meilisearch\Client::class)) {
            throw new \RuntimeException('Meilisearch PHP client is not installed. Run composer require meilisearch/meilisearch-php.');
        }

        $this->client = new \Meilisearch\Client($meilisearchUrl, $apiKey);
    }

    public function indexArticle(Article $article): void
    {
        $index = $this->client->index($this->indexName);
        $index->addDocuments([$this->serializeArticle($article)]);
    }

    public function indexAll(array $articles): void
    {
        $index = $this->client->index($this->indexName);
        $documents = array_map(
            fn(Article $a) => $this->serializeArticle($a),
            $articles
        );
        $index->addDocuments($documents);
    }

    public function removeArticle(int $articleId): void
    {
        $index = $this->client->index($this->indexName);
        $index->deleteDocument($articleId);
    }

    public function search(string $query, array $options = []): array
    {
        $index = $this->client->index($this->indexName);
        $results = $index->search($query, $options);

        return [
            'hits'             => $results->getHits(),
            'totalHits'        => $results->getEstimatedTotalHits(),
            'processingTimeMs' => $results->getProcessingTimeMs(),
        ];
    }

    public function configureIndex(): void
    {
        $index = $this->client->index($this->indexName);
        $index->updateSettings([
            'searchableAttributes' => ['title', 'content'],
            'filterableAttributes' => ['price'],
            'sortableAttributes'   => ['price', 'createdAt'],
        ]);
    }

    private function serializeArticle(Article $article): array
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
}