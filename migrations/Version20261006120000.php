<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Storage for the shipping-rate snapshot fallback (ROADMAP #5): the latest
 * known-good live rates per route/weight band, served at checkout when the live
 * carrier call returns nothing. One row per route key (country + region + weight
 * band), unique so a refresh upserts instead of accumulating rows.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shipping_rate_snapshot table (rate fallback)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shipping_rate_snapshot (
                id INT AUTO_INCREMENT NOT NULL,
                route_key VARCHAR(64) NOT NULL,
                country VARCHAR(2) NOT NULL,
                region VARCHAR(8) DEFAULT NULL,
                weight_band VARCHAR(16) NOT NULL,
                rates JSON NOT NULL,
                captured_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_SHIPPING_SNAPSHOT_ROUTE (route_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipping_rate_snapshot');
    }
}
