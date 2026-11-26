<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reservation report and transactional message outbox tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reservation_report (
                id UUID NOT NULL PRIMARY KEY,
                requested_by BIGINT NOT NULL REFERENCES app_user (id) ON DELETE RESTRICT,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'processing', 'done', 'failed')),
                artifact_key VARCHAR(100) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                started_at TIMESTAMPTZ(0) DEFAULT NULL,
                finished_at TIMESTAMPTZ(0) DEFAULT NULL,
                CONSTRAINT check_reservation_report_period CHECK (period_to >= period_from)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_reservation_report_created_at ON reservation_report (created_at DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE report_outbox (
                id UUID NOT NULL PRIMARY KEY,
                report_id UUID NOT NULL UNIQUE REFERENCES reservation_report (id) ON DELETE CASCADE,
                payload JSONB NOT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
                available_at TIMESTAMPTZ(0) NOT NULL DEFAULT now(),
                published_at TIMESTAMPTZ(0) DEFAULT NULL,
                last_error TEXT DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_report_outbox_pending ON report_outbox (available_at) WHERE published_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_outbox');
        $this->addSql('DROP TABLE reservation_report');
    }
}
