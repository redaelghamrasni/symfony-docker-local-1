<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260609234105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` ADD payment_method VARCHAR(50) DEFAULT NULL, ADD payment_brand VARCHAR(50) DEFAULT NULL, ADD payment_last4 VARCHAR(4) DEFAULT NULL, ADD stripe_payment_intent_id VARCHAR(100) DEFAULT NULL, ADD shipping_carrier_status VARCHAR(50) DEFAULT NULL, ADD shipping_date DATETIME DEFAULT NULL, ADD tracking_number VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` DROP payment_method, DROP payment_brand, DROP payment_last4, DROP stripe_payment_intent_id, DROP shipping_carrier_status, DROP shipping_date, DROP tracking_number');
    }
}
