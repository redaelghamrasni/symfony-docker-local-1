<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generalise an order's tax from three fixed Canadian columns
 * (tax_gst / tax_pst / tax_hst) into an order_tax_line child table plus a
 * tax_total convenience column. Existing orders are migrated into lines before
 * the old columns are dropped, so no charged amount is lost.
 */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace order tax columns with an order_tax_line table and tax_total';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE order_tax_line (
                id INT AUTO_INCREMENT NOT NULL,
                order_id INT NOT NULL,
                code VARCHAR(20) NOT NULL,
                label VARCHAR(40) NOT NULL,
                rate NUMERIC(6, 5) DEFAULT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                jurisdiction VARCHAR(10) DEFAULT NULL,
                INDEX IDX_2C6359BF8D9F6D38 (order_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE order_tax_line ADD CONSTRAINT FK_2C6359BF8D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE CASCADE');

        $this->addSql("ALTER TABLE `order` ADD tax_total NUMERIC(10, 2) DEFAULT '0.00' NOT NULL");

        // Backfill existing orders into lines. Rate is reconstructed from the
        // stored subtotal where possible; Quebec's provincial tax is QST.
        $this->addSql(<<<'SQL'
            INSERT INTO order_tax_line (order_id, code, label, rate, amount, jurisdiction)
            SELECT id, 'gst', 'GST',
                   CASE WHEN subtotal > 0 THEN ROUND(tax_gst / subtotal, 5) ELSE NULL END,
                   tax_gst, shipping_province
            FROM `order` WHERE tax_gst IS NOT NULL AND tax_gst > 0
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO order_tax_line (order_id, code, label, rate, amount, jurisdiction)
            SELECT id,
                   CASE WHEN shipping_province = 'QC' THEN 'qst' ELSE 'pst' END,
                   CASE WHEN shipping_province = 'QC' THEN 'QST' ELSE 'PST' END,
                   CASE WHEN subtotal > 0 THEN ROUND(tax_pst / subtotal, 5) ELSE NULL END,
                   tax_pst, shipping_province
            FROM `order` WHERE tax_pst IS NOT NULL AND tax_pst > 0
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO order_tax_line (order_id, code, label, rate, amount, jurisdiction)
            SELECT id, 'hst', 'HST',
                   CASE WHEN subtotal > 0 THEN ROUND(tax_hst / subtotal, 5) ELSE NULL END,
                   tax_hst, shipping_province
            FROM `order` WHERE tax_hst IS NOT NULL AND tax_hst > 0
        SQL);

        $this->addSql('UPDATE `order` SET tax_total = COALESCE(tax_gst,0) + COALESCE(tax_pst,0) + COALESCE(tax_hst,0)');

        $this->addSql('ALTER TABLE `order` DROP tax_gst, DROP tax_pst, DROP tax_hst');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `order` ADD tax_gst NUMERIC(10, 2) DEFAULT NULL, ADD tax_pst NUMERIC(10, 2) DEFAULT NULL, ADD tax_hst NUMERIC(10, 2) DEFAULT NULL");
        // Best-effort restore of the Canadian columns from the lines.
        $this->addSql("UPDATE `order` o SET tax_gst = (SELECT SUM(amount) FROM order_tax_line WHERE order_id = o.id AND code = 'gst')");
        $this->addSql("UPDATE `order` o SET tax_pst = (SELECT SUM(amount) FROM order_tax_line WHERE order_id = o.id AND code IN ('pst','qst'))");
        $this->addSql("UPDATE `order` o SET tax_hst = (SELECT SUM(amount) FROM order_tax_line WHERE order_id = o.id AND code = 'hst')");
        $this->addSql('ALTER TABLE order_tax_line DROP FOREIGN KEY FK_2C6359BF8D9F6D38');
        $this->addSql('DROP TABLE order_tax_line');
        $this->addSql('ALTER TABLE `order` DROP tax_total');
    }
}
