<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin-managed region overrides. Empty by default: the bundled data file
 * (config/market/regions.php) covers shipped markets, and this table only holds
 * regions a merchant adds for a new country or customises for an existing one.
 */
final class Version20261005150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the market_region table for admin-managed region overrides';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE market_region (
                id INT AUTO_INCREMENT NOT NULL,
                country VARCHAR(2) NOT NULL,
                code VARCHAR(10) NOT NULL,
                name VARCHAR(80) NOT NULL,
                position INT DEFAULT 0 NOT NULL,
                UNIQUE INDEX uniq_country_code (country, code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE market_region');
    }
}
