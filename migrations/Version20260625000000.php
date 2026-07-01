<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename order status: processing -> in_progress';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE `order` SET status = 'in_progress' WHERE status = 'processing'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE `order` SET status = 'processing' WHERE status = 'in_progress'");
    }
}
