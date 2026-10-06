<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline destinations the shipping-rate snapshot refresh rates against
 * (ROADMAP #5), so the fallback can be populated with real rates from day one,
 * before any order history exists. It holds addresses, not rates; the refresh
 * turns each into a real ShippingRateSnapshot. One row per (country, region).
 *
 * The baseline rows are seeded here (not via a DataFixture) deliberately: the
 * fixtures bundle is require-dev, absent in production, so a fixture would never
 * run on the server. Seeding in the migration mirrors the existing "statutory
 * defaults in an editable admin table" pattern — the data lands in every
 * environment on deploy and stays editable in the DB (add countries, disable
 * regions) afterwards.
 */
final class Version20261006130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shipping_destination_seed table (refresh baseline)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shipping_destination_seed (
                id INT AUTO_INCREMENT NOT NULL,
                country VARCHAR(2) NOT NULL,
                region VARCHAR(8) DEFAULT NULL,
                city VARCHAR(120) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                phone VARCHAR(30) DEFAULT NULL,
                active TINYINT(1) NOT NULL,
                UNIQUE INDEX UNIQ_SHIPPING_SEED_ROUTE (country, region),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // Initial Canadian market: one representative serviceable address per
        // region. Addresses only — the refresh rates them to get real snapshots.
        // Editable afterwards (add rows for other countries, flip `active` off).
        $this->addSql(<<<'SQL'
            INSERT INTO shipping_destination_seed (country, region, city, postal_code, active) VALUES
                ('CA', 'AB', 'Calgary',       'T2P1J9', 1),
                ('CA', 'BC', 'Vancouver',     'V6B1A1', 1),
                ('CA', 'MB', 'Winnipeg',      'R3C0V8', 1),
                ('CA', 'NB', 'Fredericton',   'E3B1E1', 1),
                ('CA', 'NL', 'St. John''s',   'A1C5M2', 1),
                ('CA', 'NS', 'Halifax',       'B3J1S9', 1),
                ('CA', 'NT', 'Yellowknife',   'X1A2L9', 1),
                ('CA', 'NU', 'Iqaluit',       'X0A0H0', 1),
                ('CA', 'ON', 'Toronto',       'M5H2N2', 1),
                ('CA', 'PE', 'Charlottetown', 'C1A1J3', 1),
                ('CA', 'QC', 'Montréal',      'H2X1Y4', 1),
                ('CA', 'SK', 'Regina',        'S4P3Y2', 1),
                ('CA', 'YT', 'Whitehorse',    'Y1A2C6', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipping_destination_seed');
    }
}
