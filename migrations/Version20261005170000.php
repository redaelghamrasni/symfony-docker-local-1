<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DB-level idempotency guard for the Stripe webhook: a unique index on
 * order.stripe_payment_intent_id so a given PaymentIntent can back at most one
 * order, no matter how many times its succeeded event is delivered (or how it
 * races the browser return). MySQL allows multiple NULLs under a UNIQUE index,
 * so PayPal orders — which carry no PaymentIntent id — are unaffected.
 */
final class Version20261005170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique index on order.stripe_payment_intent_id (webhook idempotency)';
    }

    public function up(Schema $schema): void
    {
        // A unique index cannot be built while duplicate ids already exist. In
        // practice a real PaymentIntent backs exactly one order, so any duplicate
        // is leftover test/seed data (e.g. "pi_fake"). Keep the id on the oldest
        // row of each duplicate group and NULL it on the rest — harmless, since
        // the id is only a reference to Stripe — so the index can be created.
        // On a clean database (no duplicates) this UPDATE is a no-op.
        $this->addSql(<<<'SQL'
            UPDATE `order` o
            JOIN (
                SELECT id FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY stripe_payment_intent_id ORDER BY id
                           ) AS rn
                    FROM `order`
                    WHERE stripe_payment_intent_id IS NOT NULL
                ) ranked
                WHERE ranked.rn > 1
            ) dup ON o.id = dup.id
            SET o.stripe_payment_intent_id = NULL
        SQL);

        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_STRIPE_PI ON `order` (stripe_payment_intent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORDER_STRIPE_PI ON `order`');
    }
}
