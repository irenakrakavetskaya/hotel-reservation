<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Report\Service\ReportRealtime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReportRealtimeTest extends TestCase
{
    public function testPublishesAnInvalidation(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://centrifugo:8000/api/publish', $url);
            self::assertContains('X-API-Key: api-key', $options['headers']);
            self::assertSame([
                'channel' => 'staff-reports',
                'data' => ['version' => 1, 'type' => 'report.changed', 'reportId' => 'report-id'],
            ], json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR));

            return new MockResponse('{"result":{}}');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        (new ReportRealtime($http, $logger, 'http://centrifugo:8000', 'api-key', str_repeat('x', 32)))->notify('report-id');
    }

    public function testLogsPublishFailuresWithoutThrowing(): void
    {
        foreach ([
            new MockResponse('{"error":{"code":101}}'),
            new MockResponse('{"error":{"code":500}}', ['http_code' => 500]),
            new MockResponse('not-json'),
            new TransportException('connection failed'),
        ] as $result) {
            $http = new MockHttpClient(static function () use ($result): MockResponse {
                if ($result instanceof TransportException) {
                    throw $result;
                }

                return $result;
            });
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error');
            (new ReportRealtime($http, $logger, 'http://centrifugo:8000', 'api-key', str_repeat('x', 32)))->notify('report-id');
        }
    }
}
