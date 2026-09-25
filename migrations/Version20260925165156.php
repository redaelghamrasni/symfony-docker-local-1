<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925165156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the currency on carts and orders, defaulting existing rows to CAD';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart ADD currency VARCHAR(3) DEFAULT \'CAD\' NOT NULL');
        $this->addSql('ALTER TABLE `order` ADD currency VARCHAR(3) DEFAULT \'CAD\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart DROP currency');
        $this->addSql('ALTER TABLE `order` DROP currency');
    }
}
