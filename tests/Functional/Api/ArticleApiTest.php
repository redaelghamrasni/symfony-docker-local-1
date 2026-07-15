<?php

namespace App\Tests\Functional\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ArticleApiTest extends WebTestCase
{
    public function testGetArticlesList(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/api/articles', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();

        $contentType = $client->getResponse()->headers->get('Content-Type');
        $this->assertStringContainsString('json', $contentType);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testGetSingleArticle(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/api/articles/1', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [200, 404]
        );
    }

    public function testCreateArticleRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/fr/api/articles', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'title'   => 'Test',
            'content' => 'Test content',
            'price'   => 29.99,
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetArticlesReturnsJsonStructure(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/api/articles', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);

        if (!empty($data)) {
            $first = $data[0]
                ?? $data['member'][0]
                ?? $data['hydra:member'][0]
                ?? null;

            if ($first) {
                $this->assertArrayHasKey('id', $first);
                $this->assertArrayHasKey('title', $first);
            }
        }
    }
}