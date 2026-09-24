<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `room_type_inventory` table.
 *
 * IMPORTANT — do not touch `check_room_count` without reading
 * CLAUDE.md invariant #2 and docs/IMPLEMENTATION_PLAN.md §3 "Concurrency &
 * idempotency" first. This constraint is the actual guarantee behind the
 * 10% overbooking limit — application-level availability checks are a UX
 * nicety on top of it, never a substitute for it. Any migration that alters
 * this table must preserve an equivalent constraint.
 *
 * Rows are pre-populated by a scheduled job for a rolling ~2-year window —
 * see docs/IMPLEMENTATION_PLAN.md §1 and §8 (build order, step 2). This
 * migration only creates the table shape; it does not seed data.
 */
final class Version20260101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create room_type_inventory table with 10% overbooking CHECK constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE room_type_inventory (
                hotel_id BIGINT NOT NULL,
                room_type_id BIGINT NOT NULL,
                date DATE NOT NULL,
                total_inventory INTEGER NOT NULL CHECK (total_inventory >= 0),
                total_reserved INTEGER NOT NULL DEFAULT 0 CHECK (total_reserved >= 0),
                updated_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                PRIMARY KEY (hotel_id, room_type_id, date),
                CONSTRAINT fk_room_type_inventory_hotel
                    FOREIGN KEY (hotel_id) REFERENCES hotel (id) ON DELETE CASCADE,
                CONSTRAINT fk_room_type_inventory_room_type
                    FOREIGN KEY (room_type_id) REFERENCES room_type (id) ON DELETE CASCADE,
                CONSTRAINT check_room_count
                    CHECK (total_reserved <= (total_inventory * 1.1)::int)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_room_type_inventory_lookup ON room_type_inventory (hotel_id, room_type_id, date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_type_inventory');
    }
}
