<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Message\ReportResultMessage;
use App\Domain\Report\Service\ReportService;
use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
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

    public function testRealtimeTokenRequiresStaffAndHasScopedShortLivedClaims(): void
    {
        $staffToken = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $userToken = $this->tokenFor('customer@example.com', ['ROLE_USER']);

        $this->client->request('GET', '/v1/admin/realtime/token');
        self::assertResponseStatusCodeSame(401);
        $this->request('GET', '/v1/admin/realtime/token', $userToken);
        self::assertResponseStatusCodeSame(403);

        $body = $this->request('GET', '/v1/admin/realtime/token', $staffToken);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $secret = (string) ($_ENV['CENTRIFUGO_TOKEN_SECRET'] ?? $_SERVER['CENTRIFUGO_TOKEN_SECRET']);
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        $jwt = $config->parser()->parse($body['token']);
        self::assertInstanceOf(UnencryptedToken::class, $jwt);
        self::assertTrue($config->validator()->validate($jwt, new SignedWith($config->signer(), $config->verificationKey())));
        self::assertSame('hotel-reservation', $jwt->claims()->get('iss'));
        self::assertSame(['centrifugo'], $jwt->claims()->get('aud'));
        self::assertSame(['staff-reports'], $jwt->claims()->get('channels'));
        self::assertNotSame('', $jwt->claims()->get('sub'));
        self::assertSame(120, $jwt->claims()->get('exp')->getTimestamp() - $jwt->claims()->get('iat')->getTimestamp());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE app_user SET roles = ? WHERE email = ?', [json_encode(['ROLE_USER'], JSON_THROW_ON_ERROR), 'staff@example.com']);
        $this->request('GET', '/v1/admin/realtime/token', $staffToken);
        self::assertResponseStatusCodeSame(403);
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
