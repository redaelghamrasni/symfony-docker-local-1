<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the redundant article.name column (duplicate of article.title).
 */
final class Version20260910032708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the redundant article.name column, a duplicate of article.title';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD name VARCHAR(255) NOT NULL');
        $this->addSql('UPDATE article SET name = title');
    }
}
