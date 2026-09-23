<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `room` table — individual physical rooms, used for admin and
 * maintenance purposes. Reservations are made against room *types*, not
 * specific rooms — see docs/IMPLEMENTATION_PLAN.md §1 "Data model" — `room`.
 */
final class Version20260101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create room table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE room (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                hotel_id BIGINT NOT NULL REFERENCES hotel (id) ON DELETE CASCADE,
                room_type_id BIGINT NOT NULL REFERENCES room_type (id) ON DELETE RESTRICT,
                room_number VARCHAR(20) NOT NULL,
                floor SMALLINT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'maintenance', 'inactive')),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uniq_room_hotel_room_number UNIQUE (hotel_id, room_number)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_room_hotel_id ON room (hotel_id)');
        $this->addSql('CREATE INDEX idx_room_room_type_id ON room (room_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room');
    }
}
