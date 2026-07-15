<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703002924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order price breakdown columns (subtotal, shipping, taxes) and user locale preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD subtotal NUMERIC(10, 2) DEFAULT NULL, ADD shipping_amount NUMERIC(10, 2) DEFAULT NULL, ADD tax_gst NUMERIC(10, 2) DEFAULT NULL, ADD tax_pst NUMERIC(10, 2) DEFAULT NULL, ADD tax_hst NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD locale VARCHAR(2) DEFAULT \'fr\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP subtotal, DROP shipping_amount, DROP tax_gst, DROP tax_pst, DROP tax_hst');
        $this->addSql('ALTER TABLE `user` DROP locale');
    }
}
