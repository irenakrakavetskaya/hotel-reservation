<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `room_type_rate` table — per-date dynamic pricing, populated by
 * the pricing job described in docs/IMPLEMENTATION_PLAN.md §4 "Dynamic pricing".
 *
 * `price` is stored in integer minor units (cents), same convention as
 * `room_type.base_price`.
 */
final class Version20260101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create room_type_rate table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE room_type_rate (
                hotel_id BIGINT NOT NULL,
                room_type_id BIGINT NOT NULL,
                date DATE NOT NULL,
                price INTEGER NOT NULL CHECK (price >= 0),
                updated_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                PRIMARY KEY (hotel_id, room_type_id, date),
                CONSTRAINT fk_room_type_rate_hotel
                    FOREIGN KEY (hotel_id) REFERENCES hotel (id) ON DELETE CASCADE,
                CONSTRAINT fk_room_type_rate_room_type
                    FOREIGN KEY (room_type_id) REFERENCES room_type (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_room_type_rate_lookup ON room_type_rate (hotel_id, room_type_id, date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_type_rate');
    }
}
