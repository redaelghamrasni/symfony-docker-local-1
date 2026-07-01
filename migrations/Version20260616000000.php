<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add province to shipping/billing addresses, estimated delivery date, and shipping label URL to order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD shipping_province VARCHAR(100) DEFAULT NULL, ADD billing_province VARCHAR(100) DEFAULT NULL, ADD estimated_delivery_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD shipping_label_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP shipping_province, DROP billing_province, DROP estimated_delivery_date, DROP shipping_label_url');
    }
}
