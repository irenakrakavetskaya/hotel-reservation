<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `hotel` table.
 *
 * See docs/IMPLEMENTATION_PLAN.md §1 "Data model" — `hotel`.
 */
final class Version20260101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hotel table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE hotel (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                address VARCHAR(255) NOT NULL,
                city VARCHAR(120) NOT NULL,
                country VARCHAR(120) NOT NULL,
                star_rating SMALLINT NOT NULL CHECK (star_rating BETWEEN 1 AND 5),
                description TEXT DEFAULT NULL,
                amenities JSONB NOT NULL DEFAULT '[]'::jsonb,
                created_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ(0) NOT NULL DEFAULT now()
            )
        SQL);

        $this->addSql('CREATE INDEX idx_hotel_city ON hotel (city)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE hotel');
    }
}
