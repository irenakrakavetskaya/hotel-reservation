<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `room_type` table.
 *
 * `base_price` is stored in integer minor units (cents) per
 * .claude/rules/backend-symfony.md — never floats for money.
 *
 * See docs/IMPLEMENTATION_PLAN.md §1 "Data model" — `room_type`.
 */
final class Version20260101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create room_type table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE room_type (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                hotel_id BIGINT NOT NULL REFERENCES hotel (id) ON DELETE CASCADE,
                name VARCHAR(120) NOT NULL,
                description TEXT DEFAULT NULL,
                max_occupancy SMALLINT NOT NULL CHECK (max_occupancy > 0),
                base_price INTEGER NOT NULL CHECK (base_price >= 0),
                amenities JSONB NOT NULL DEFAULT '[]'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);

        $this->addSql('CREATE INDEX idx_room_type_hotel_id ON room_type (hotel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_type');
    }
}
