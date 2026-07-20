<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add article_image table for the article images gallery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_image (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(500) NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, INDEX IDX_2FF54D767294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_image ADD CONSTRAINT FK_2FF54D767294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_image DROP FOREIGN KEY FK_2FF54D767294869C');
        $this->addSql('DROP TABLE article_image');
    }
}
