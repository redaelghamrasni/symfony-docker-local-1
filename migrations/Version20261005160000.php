<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Physical shipping data on articles: weight (kg) plus the three box dimensions
 * length/width/height (cm). All are required with sensible starting defaults so
 * every existing article is immediately shippable — weight 0.5 kg and each
 * dimension 1 cm — and the backfill sets those same values on current rows.
 */
final class Version20261005160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add weight and dimensions to article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE article
                ADD weight NUMERIC(7, 3) DEFAULT '0.500' NOT NULL,
                ADD length NUMERIC(7, 2) DEFAULT '1.00' NOT NULL,
                ADD width NUMERIC(7, 2) DEFAULT '1.00' NOT NULL,
                ADD height NUMERIC(7, 2) DEFAULT '1.00' NOT NULL
        SQL);

        // Existing rows take the starting defaults explicitly, so the data is
        // set rather than relying only on the column defaults.
        $this->addSql("UPDATE article SET weight = '0.500', length = '1.00', width = '1.00', height = '1.00'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP weight, DROP length, DROP width, DROP height');
    }
}
