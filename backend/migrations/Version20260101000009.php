<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the foreign key deferred in Version20260101000006.
 *
 * ON DELETE RESTRICT: reservations are booking/payment records, so a user
 * who has reservations cannot be deleted out from under them.
 */
final class Version20260101000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation.user_id foreign key to app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM reservation r
                    WHERE NOT EXISTS (SELECT 1 FROM app_user u WHERE u.id = r.user_id)
                ) THEN
                    RAISE EXCEPTION 'reservation rows reference missing app_user ids; fix them before adding fk_reservation_user';
                END IF;
            END
            $$
        SQL);
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP CONSTRAINT fk_reservation_user');
    }
}
