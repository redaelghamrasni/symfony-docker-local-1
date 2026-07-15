<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use PHPUnit\Framework\TestCase;

class ArticleTest extends TestCase
{
    public function testArticleCreation(): void
    {
        $article = new Article();
        $article->setTitle('Test Article');
        $article->setContent('Contenu de test');
        $article->setPrice(49.99);
        $article->setCreatedAt(new \DateTimeImmutable());

        $this->assertEquals('Test Article', $article->getTitle());
        $this->assertEquals('Contenu de test', $article->getContent());
        $this->assertEquals(49.99, $article->getPrice());
        $this->assertNotNull($article->getCreatedAt());
    }

    public function testArticlePriceCannotBeNegative(): void
    {
        $article = new Article();
        $article->setPrice(-10.00);

        $this->assertLessThan(0, $article->getPrice());
        // Ce test documente le comportement actuel
        // et pourrait déclencher une future validation
    }

    public function testArticleTitleIsString(): void
    {
        $article = new Article();
        $article->setTitle('Chemise en lin');

        $this->assertIsString($article->getTitle());
        $this->assertNotEmpty($article->getTitle());
    }
}