<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users for JWT authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                email VARCHAR(180) NOT NULL,
                roles JSONB NOT NULL DEFAULT '[]'::jsonb,
                password VARCHAR(255) NOT NULL,
                CONSTRAINT uniq_app_user_email UNIQUE (email)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user');
    }
}
