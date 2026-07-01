<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514022000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed categories and link existing articles';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $categories = [
            ['Informatique',             'informatique'],
            ['Smartphones & Tablettes',  'smartphones-tablettes'],
            ['Audio',                    'audio'],
            ['Écrans & Stockage',        'ecrans-stockage'],
            ['Gaming',                   'gaming'],
            ['Bureau & Accessoires',     'bureau-accessoires'],
        ];

        foreach ($categories as [$name, $slug]) {
            $this->addSql(
                'INSERT INTO category (name, slug, created_at) VALUES (?, ?, ?)',
                [$name, $slug, $now]
            );
        }

        // article_id => category slug
        $links = [
            61 => 'informatique',
            64 => 'informatique',
            65 => 'informatique',
            68 => 'informatique',
            80 => 'informatique',
            62 => 'smartphones-tablettes',
            70 => 'smartphones-tablettes',
            75 => 'smartphones-tablettes',
            63 => 'audio',
            71 => 'audio',
            77 => 'audio',
            66 => 'ecrans-stockage',
            67 => 'ecrans-stockage',
            76 => 'ecrans-stockage',
            74 => 'gaming',
            78 => 'gaming',
            69 => 'bureau-accessoires',
            72 => 'bureau-accessoires',
            73 => 'bureau-accessoires',
            79 => 'bureau-accessoires',
        ];

        foreach ($links as $articleId => $slug) {
            $this->addSql(
                'UPDATE article SET category_id = (SELECT id FROM category WHERE slug = ?) WHERE id = ?',
                [$slug, $articleId]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE article SET category_id = NULL');
        $this->addSql('DELETE FROM category');
    }
}
