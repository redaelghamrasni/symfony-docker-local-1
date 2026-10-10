<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Quote → Order lifecycle (roadmap #2): a mutable pre-payment object that
 * always exists before a payment is attempted, so the Stripe webhook can find
 * and convert it even when the browser never returns. Creates the `quote` table
 * (snapshot of customer, addresses, chosen shipping, amounts, PI linkage,
 * lifecycle status + timestamps) plus the snapshot children `quote_item` and
 * `quote_tax_line`, mirroring order / order_item / order_tax_line. The payment
 * linkage is provider-neutral — (payment_provider, payment_reference) with a
 * unique index, not a Stripe-specific column — so no concrete payment
 * implementation leaks into the quote schema; that pair is the DB-level
 * idempotency anchor the converter relies on. See docs/quote-lifecycle-plan.md.
 */
final class Version20261010014034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create quote / quote_item / quote_tax_line tables (Quote→Order lifecycle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quote (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                status VARCHAR(50) NOT NULL,
                session_id VARCHAR(128) DEFAULT NULL,
                total NUMERIC(10, 2) DEFAULT NULL,
                subtotal NUMERIC(10, 2) DEFAULT NULL,
                shipping_amount NUMERIC(10, 2) DEFAULT NULL,
                tax_total NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                currency VARCHAR(3) DEFAULT 'CAD' NOT NULL,
                shipping_method_carrier VARCHAR(100) DEFAULT NULL,
                shipping_method_name VARCHAR(150) DEFAULT NULL,
                shipping_method_reference VARCHAR(100) DEFAULT NULL,
                customer_first_name VARCHAR(100) DEFAULT NULL,
                customer_last_name VARCHAR(100) DEFAULT NULL,
                customer_email VARCHAR(180) DEFAULT NULL,
                customer_phone VARCHAR(20) DEFAULT NULL,
                shipping_street VARCHAR(255) DEFAULT NULL,
                shipping_city VARCHAR(100) DEFAULT NULL,
                shipping_postal_code VARCHAR(20) DEFAULT NULL,
                shipping_province VARCHAR(100) DEFAULT NULL,
                shipping_country VARCHAR(2) DEFAULT 'CA' NOT NULL,
                billing_street VARCHAR(255) DEFAULT NULL,
                billing_city VARCHAR(100) DEFAULT NULL,
                billing_postal_code VARCHAR(20) DEFAULT NULL,
                billing_province VARCHAR(100) DEFAULT NULL,
                payment_provider VARCHAR(30) DEFAULT NULL,
                payment_reference VARCHAR(191) DEFAULT NULL,
                converted_order_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                ready_at DATETIME DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                abandoned_at DATETIME DEFAULT NULL,
                INDEX IDX_6B71CBF4A76ED395 (user_id),
                UNIQUE INDEX UNIQ_QUOTE_PAYMENT (payment_provider, payment_reference),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quote_item (
                id INT AUTO_INCREMENT NOT NULL,
                quote_id INT NOT NULL,
                article_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price NUMERIC(10, 2) NOT NULL,
                subtotal NUMERIC(10, 2) NOT NULL,
                INDEX IDX_8DFC7A94DB805178 (quote_id),
                INDEX IDX_8DFC7A947294869C (article_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quote_tax_line (
                id INT AUTO_INCREMENT NOT NULL,
                quote_id INT NOT NULL,
                code VARCHAR(20) NOT NULL,
                label VARCHAR(40) NOT NULL,
                rate NUMERIC(6, 5) DEFAULT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                jurisdiction VARCHAR(10) DEFAULT NULL,
                INDEX IDX_27A1139FDB805178 (quote_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_6B71CBF4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quote_item ADD CONSTRAINT FK_8DFC7A94DB805178 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quote_item ADD CONSTRAINT FK_8DFC7A947294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE quote_tax_line ADD CONSTRAINT FK_27A1139FDB805178 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote_item DROP FOREIGN KEY FK_8DFC7A947294869C');
        $this->addSql('ALTER TABLE quote_item DROP FOREIGN KEY FK_8DFC7A94DB805178');
        $this->addSql('ALTER TABLE quote_tax_line DROP FOREIGN KEY FK_27A1139FDB805178');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF4A76ED395');
        $this->addSql('DROP TABLE quote_item');
        $this->addSql('DROP TABLE quote_tax_line');
        $this->addSql('DROP TABLE quote');
    }
}
