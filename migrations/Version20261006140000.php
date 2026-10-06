<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the editable `shipping.snapshot.throttle` setting (seconds to pause
 * between carrier calls during the snapshot refresh — the API rate-limit pacing,
 * ROADMAP #5), so an operator can tune it from the back-office without a
 * redeploy. Same "seed an editable setting via migration" approach used for the
 * other settings and the destination baseline (the fixtures bundle is
 * require-dev, absent in prod). INSERT IGNORE so a dev DB that already loaded it
 * via SettingFixtures is untouched.
 */
final class Version20261006140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed shipping.snapshot.throttle setting (refresh call spacing)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO setting (`key`, value, label, type) VALUES
                ('shipping.snapshot.throttle', '1', 'Shipping rate refresh — seconds between carrier calls', 'number')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE `key` = 'shipping.snapshot.throttle'");
    }
}
