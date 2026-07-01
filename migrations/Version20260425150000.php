<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add roles column to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD roles JSON NOT NULL DEFAULT (JSON_ARRAY(\'ROLE_USER\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN roles');
    }
}
