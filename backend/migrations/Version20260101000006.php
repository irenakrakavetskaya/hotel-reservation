<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `reservation` table.
 *
 * `id` is a client-supplied UUID and doubles as the idempotency key — it is
 * deliberately NOT auto-generated. See CLAUDE.md invariant #1 and
 * docs/IMPLEMENTATION_PLAN.md §3 "Concurrency & idempotency". Never add
 * GENERATED/DEFAULT to this column.
 *
 * `user_id` has no FK constraint yet because the user/auth table isn't part
 * of this data model slice — add the FK once the auth schema exists rather
 * than dropping the column's intent.
 *
 * `total_price` is stored in integer minor units (cents), same convention as
 * `room_type.base_price` and `room_type_rate.price`.
 */
final class Version20260101000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reservation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reservation (
                id UUID NOT NULL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                hotel_id BIGINT NOT NULL REFERENCES hotel (id) ON DELETE RESTRICT,
                room_type_id BIGINT NOT NULL REFERENCES room_type (id) ON DELETE RESTRICT,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                room_count INTEGER NOT NULL CHECK (room_count > 0),
                status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'paid', 'refunded', 'canceled', 'rejected')),
                total_price INTEGER NOT NULL CHECK (total_price >= 0),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT check_reservation_dates CHECK (end_date > start_date)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_reservation_user_id ON reservation (user_id)');
        $this->addSql('CREATE INDEX idx_reservation_hotel_room_type_dates ON reservation (hotel_id, room_type_id, start_date, end_date)');
        $this->addSql('CREATE INDEX idx_reservation_status ON reservation (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation');
    }
}
