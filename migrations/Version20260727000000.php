<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping method snapshot columns (carrier, name, reference) to order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD shipping_method_carrier VARCHAR(100) DEFAULT NULL, ADD shipping_method_name VARCHAR(150) DEFAULT NULL, ADD shipping_method_reference VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP shipping_method_carrier, DROP shipping_method_name, DROP shipping_method_reference');
    }
}
