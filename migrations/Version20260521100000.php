<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_url to article and category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD image_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD image_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP COLUMN image_url');
        $this->addSql('ALTER TABLE category DROP COLUMN image_url');
    }
}
