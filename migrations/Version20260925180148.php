<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925180148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the currency table and per-article currency prices, seeding CAD as the default';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article_price (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, article_id INT NOT NULL, currency_id INT NOT NULL, INDEX IDX_BD7F50C87294869C (article_id), INDEX IDX_BD7F50C838248176 (currency_id), UNIQUE INDEX uniq_article_currency (article_id, currency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE currency (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(3) NOT NULL, name VARCHAR(60) NOT NULL, symbol VARCHAR(8) NOT NULL, symbol_position VARCHAR(6) DEFAULT \'before\' NOT NULL, exchange_rate NUMERIC(12, 6) DEFAULT \'1.000000\' NOT NULL, enabled TINYINT DEFAULT 0 NOT NULL, is_default TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_6956883F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_price ADD CONSTRAINT FK_BD7F50C87294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_price ADD CONSTRAINT FK_BD7F50C838248176 FOREIGN KEY (currency_id) REFERENCES currency (id) ON DELETE CASCADE');

        // Seed the currency the shop already runs on. Without a default row
        // the catalogue would have no currency to price in between this
        // migration and an admin creating one by hand, so it is part of the
        // schema change rather than a manual follow-up step.
        $this->addSql(<<<'SQL'
            INSERT INTO currency (code, name, symbol, symbol_position, exchange_rate, enabled, is_default, position, created_at)
            VALUES ('CAD', 'Canadian dollar', '$', 'before', 1.000000, 1, 1, 0, NOW())
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article_price DROP FOREIGN KEY FK_BD7F50C87294869C');
        $this->addSql('ALTER TABLE article_price DROP FOREIGN KEY FK_BD7F50C838248176');
        $this->addSql('DROP TABLE article_price');
        $this->addSql('DROP TABLE currency');
    }
}
