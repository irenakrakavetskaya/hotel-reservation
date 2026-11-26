<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Message\ReportResultMessage;
use App\Domain\Report\Repository\ReportRepository;
use App\Domain\Report\Service\ReportOutboxPublisher;
use App\Domain\Report\Service\ReportRealtime;
use App\Domain\Report\Service\ReportService;
use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReportRealtimeNotificationsTest extends WebTestCase
{
    use CreatesTokens;

    public function testChangesNotifyAfterCommitAndDuplicatesDoNot(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE reservation_report, app_user RESTART IDENTITY CASCADE');
        $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $userId = (int) $connection->fetchOne('SELECT id FROM app_user WHERE email = ?', ['staff@example.com']);
        $observed = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($connection, &$observed): MockResponse {
            self::assertFalse($connection->isTransactionActive());
            $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $observed[] = $connection->fetchOne('SELECT status FROM reservation_report WHERE id = ?', [$payload['data']['reportId']]);

            return new MockResponse('{"result":{}}');
        });
        $realtime = new ReportRealtime($http, new NullLogger(), 'http://centrifugo:8000', 'key', str_repeat('x', 32));
        $service = new ReportService(
            self::getContainer()->get(EntityManagerInterface::class),
            $connection,
            self::getContainer()->get(ReportRepository::class),
            $realtime,
        );
        $report = $service->create($userId, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $id = $report->getId()->toRfc4122();
        $service->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Processing));
        $service->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Done, 'report-' . $id . '.pdf'));
        $service->applyWorkerResult(new ReportResultMessage($id, ReportStatus::Processing));
        self::assertSame(['pending', 'processing', 'done'], $observed);

        $second = $service->create($userId, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
        $secondId = $second->getId()->toRfc4122();
        $connection->executeStatement("UPDATE report_outbox SET published_at = now() WHERE report_id = ?", [$id]);
        $connection->executeStatement("UPDATE report_outbox SET attempt_count = 9, payload = '{}' WHERE report_id = ?", [$secondId]);
        $publisher = new ReportOutboxPublisher($connection, $this->createStub(MessageBusInterface::class), new NullLogger(), $realtime);
        $publisher->publishBatch();
        self::assertSame(['pending', 'processing', 'done', 'pending', 'failed'], $observed);
        $publisher->publishBatch();
        self::assertCount(5, $observed);
    }
}
