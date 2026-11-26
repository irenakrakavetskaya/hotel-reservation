<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Message\ReportResultMessage;
use App\Domain\Report\Service\ReportService;
use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReportControllerTest extends WebTestCase
{
    use CreatesTokens;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE reservation_report, app_user RESTART IDENTITY CASCADE');
    }

    public function testStaffCanCreateAndListAReport(): void
    {
        $token = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertResponseStatusCodeSame(201);
        $report = $this->responseBody();
        self::assertSame('2026-01-01', $report['from']);
        self::assertSame('2026-01-31', $report['to']);
        self::assertSame('pending', $report['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $report['id']);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_outbox WHERE report_id = :id', ['id' => $report['id']]));

        $listed = $this->request('GET', '/v1/admin/reports', $token);
        self::assertCount(1, $listed);
        self::assertSame($report['id'], $listed[0]['id']);

        $loaded = $this->request('GET', '/v1/admin/reports/' . $report['id'], $token);
        self::assertSame('pending', $loaded['status']);
    }

    public function testRejectsInvalidOrOverlongReportPeriod(): void
    {
        $token = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);

        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2026-02-01', 'to' => '2026-01-01']);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2025-01-01', 'to' => '2026-01-01']);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2099-01-01', 'to' => '2099-01-02']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testStaffCannotDownloadAReportBeforeItIsDone(): void
    {
        $token = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2026-01-01', 'to' => '2026-01-02']);
        $id = $this->responseBody()['id'];

        $this->request('GET', '/v1/admin/reports/' . $id . '/download', $token);
        self::assertResponseStatusCodeSame(409);
    }

    public function testWorkerResultsAreIdempotentAndTerminalStatusDoesNotChange(): void
    {
        $token = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $this->request('POST', '/v1/admin/reports', $token, ['from' => '2026-01-01', 'to' => '2026-01-02']);
        $id = $this->responseBody()['id'];

        /** @var ReportService $reports */
        $reports = self::getContainer()->get(ReportService::class);
        $reports->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Processing));
        $reports->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Done, 'report-' . $id . '.pdf'));
        $reports->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Processing));
        $reports->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Failed, error: 'late duplicate'));

        $report = $this->request('GET', '/v1/admin/reports/' . $id, $token);
        self::assertSame('done', $report['status']);
        self::assertNull($report['error']);
    }

    /**
     * @param array<string, string>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $this->client->request(
            $method,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this->responseBody();
    }

    /** @return array<mixed> */
    private function responseBody(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
