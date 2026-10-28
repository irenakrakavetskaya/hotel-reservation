<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Floor the check_room_count overbooking ceiling using exact integer math';
    }

    public function up(Schema $schema): void
    {
        // (total_inventory * 1.1)::int rounds (5 -> 6); this is floor(inv * 1.1).
        $this->addSql('ALTER TABLE room_type_inventory DROP CONSTRAINT check_room_count');
        $this->addSql('ALTER TABLE room_type_inventory ADD CONSTRAINT check_room_count CHECK (total_reserved * 10 <= total_inventory * 11)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room_type_inventory DROP CONSTRAINT check_room_count');
        $this->addSql('ALTER TABLE room_type_inventory ADD CONSTRAINT check_room_count CHECK (total_reserved <= (total_inventory * 1.1)::int)');
    }
}
