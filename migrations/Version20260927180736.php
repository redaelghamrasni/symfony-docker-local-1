<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927180736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the tax_rate table and seed it with the current Canadian provincial rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tax_rate (id INT AUTO_INCREMENT NOT NULL, province VARCHAR(5) NOT NULL, name VARCHAR(60) NOT NULL, gst NUMERIC(6, 5) DEFAULT \'0.00000\' NOT NULL, pst NUMERIC(6, 5) DEFAULT \'0.00000\' NOT NULL, hst NUMERIC(6, 5) DEFAULT \'0.00000\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_C36330C14ADAD40B (province), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // Seed with the rates that were previously hardcoded, so checkout keeps
        // taxing correctly from the moment the code that reads this table ships.
        // NS is 0.14 (the current statutory HST) rather than the stale 0.15 the
        // old CheckoutController constant carried.
        $rows = [
            ['AB', 'Alberta',                   '0.05000', '0.00000', '0.00000'],
            ['BC', 'British Columbia',          '0.05000', '0.07000', '0.00000'],
            ['MB', 'Manitoba',                  '0.05000', '0.07000', '0.00000'],
            ['NB', 'New Brunswick',             '0.00000', '0.00000', '0.15000'],
            ['NL', 'Newfoundland and Labrador', '0.00000', '0.00000', '0.15000'],
            ['NS', 'Nova Scotia',               '0.00000', '0.00000', '0.14000'],
            ['NT', 'Northwest Territories',     '0.05000', '0.00000', '0.00000'],
            ['NU', 'Nunavut',                   '0.05000', '0.00000', '0.00000'],
            ['ON', 'Ontario',                   '0.00000', '0.00000', '0.13000'],
            ['PE', 'Prince Edward Island',      '0.00000', '0.00000', '0.15000'],
            ['QC', 'Quebec',                    '0.05000', '0.09975', '0.00000'],
            ['SK', 'Saskatchewan',              '0.05000', '0.06000', '0.00000'],
            ['YT', 'Yukon',                     '0.05000', '0.00000', '0.00000'],
        ];

        foreach ($rows as [$province, $name, $gst, $pst, $hst]) {
            $this->addSql(
                'INSERT INTO tax_rate (province, name, gst, pst, hst, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [$province, $name, $gst, $pst, $hst]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tax_rate');
    }
}
