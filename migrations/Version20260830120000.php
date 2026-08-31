<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the chatbot.model setting used to pick the Ollama model for the support chatbot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO setting (`key`, value, label, type) VALUES ('chatbot.model', 'qwen2.5', 'Chatbot model', 'select')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE `key` = 'chatbot.model'");
    }
}
