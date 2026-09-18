<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the chatbot.enabled setting used to fully disable the chatbot (and block model downloads)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO setting (`key`, value, label, type) VALUES ('chatbot.enabled', '1', 'Enable chatbot', 'checkbox')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE `key` = 'chatbot.enabled'");
    }
}
