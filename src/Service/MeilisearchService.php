<?php

namespace App\Service;

/**
 * Thin generic wrapper around the Meilisearch PHP client.
 *
 * Callers own document serialization and per-index settings (searchable/filterable
 * attributes) — this service just knows how to push documents into a named index,
 * remove one, search it, and configure it. See MeilisearchReindexCommand for the
 * per-entity indexing logic (articles, categories, users, orders).
 */
class MeilisearchService
{
    private \Meilisearch\Client $client;

    public function __construct(string $meilisearchUrl, string $apiKey)
    {
        if (!class_exists(\Meilisearch\Client::class)) {
            throw new \RuntimeException('Meilisearch PHP client is not installed. Run composer require meilisearch/meilisearch-php.');
        }

        $this->client = new \Meilisearch\Client($meilisearchUrl, $apiKey);
    }

    public function index(string $indexName, array $documents): void
    {
        $this->client->index($indexName)->addDocuments($documents);
    }

    public function removeDocument(string $indexName, int|string $id): void
    {
        $this->client->index($indexName)->deleteDocument($id);
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $results = $this->client->index($indexName)->search($query, $options);

        return [
            'hits'             => $results->getHits(),
            'totalHits'        => $results->getEstimatedTotalHits(),
            'processingTimeMs' => $results->getProcessingTimeMs(),
        ];
    }

    public function configureIndex(string $indexName, array $settings): void
    {
        $this->client->index($indexName)->updateSettings($settings);
    }
}
