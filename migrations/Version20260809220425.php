<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809220425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add group_key to faq_entry to pair FR/EN translations of the same question';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE faq_entry ADD group_key VARCHAR(32) DEFAULT NULL');
        $this->addSql("UPDATE faq_entry SET group_key = SUBSTRING(MD5(RAND()), 1, 16) WHERE group_key IS NULL");
        $this->addSql('ALTER TABLE faq_entry CHANGE group_key group_key VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE faq_entry DROP group_key');
    }
}
