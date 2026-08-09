<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808173334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE faq_entry (id INT AUTO_INCREMENT NOT NULL, question LONGTEXT NOT NULL, answer LONGTEXT NOT NULL, locale VARCHAR(5) NOT NULL, category VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_image RENAME INDEX idx_2ff54d767294869c TO IDX_B28A764E7294869C');
        $this->addSql('ALTER TABLE `order` CHANGE estimated_delivery_date estimated_delivery_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE setting CHANGE type type VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE faq_entry');
        $this->addSql('ALTER TABLE article_image RENAME INDEX idx_b28a764e7294869c TO IDX_2FF54D767294869C');
        $this->addSql('ALTER TABLE `order` CHANGE estimated_delivery_date estimated_delivery_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE setting CHANGE type type VARCHAR(50) DEFAULT \'text\' NOT NULL');
    }
}
