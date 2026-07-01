<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add province to address; create setting table; add shipping/tax fields to session support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD province VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE TABLE setting (`key` VARCHAR(100) NOT NULL, value LONGTEXT DEFAULT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL DEFAULT \'text\', PRIMARY KEY(`key`)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        // Seed default settings
        $this->addSql("INSERT IGNORE INTO setting (`key`, value, label, type) VALUES ('shipping.free_threshold', NULL, 'Seuil de livraison gratuite (\$)', 'number')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP COLUMN province');
        $this->addSql('DROP TABLE setting');
    }
}
