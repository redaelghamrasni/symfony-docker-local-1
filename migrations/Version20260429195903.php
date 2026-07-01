<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429195903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE password_reset_token CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE password_reset_token RENAME INDEX uniq_prt_token TO UNIQ_6B7BA4B65F37A13B');
        $this->addSql('ALTER TABLE password_reset_token RENAME INDEX idx_prt_user TO IDX_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE password_reset_token CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE password_reset_token RENAME INDEX idx_6b7ba4b6a76ed395 TO IDX_PRT_USER');
        $this->addSql('ALTER TABLE password_reset_token RENAME INDEX uniq_6b7ba4b65f37a13b TO UNIQ_PRT_TOKEN');
        $this->addSql('ALTER TABLE `user` CHANGE roles roles JSON DEFAULT \'json_array(_utf8mb4\\\\\'\'ROLE_USER\\\\\'\')\' NOT NULL');
    }
}
