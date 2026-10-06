<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the home_country setting (default CA) that drives the market layer';
    }

    public function up(Schema $schema): void
    {
        // INSERT ... ON DUPLICATE: harmless if a fixture already created the row.
        $this->addSql(<<<'SQL'
            INSERT INTO setting (`key`, value, label, type)
            VALUES ('home_country', 'CA', 'Home country', 'country')
            ON DUPLICATE KEY UPDATE `key` = `key`
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE `key` = 'home_country'");
    }
}
