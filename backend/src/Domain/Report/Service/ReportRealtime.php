<?php

declare(strict_types=1);

namespace App\Domain\Report\Service;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReportRealtime
{
    public const CHANNEL = 'staff-reports';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly string $tokenSecret,
    ) {}

    public function connectionToken(int $userId): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->tokenSecret));

        return $config->builder()
            ->issuedBy('hotel-reservation')
            ->permittedFor('centrifugo')
            ->relatedTo((string) $userId)
            ->issuedAt($now)
            ->expiresAt($now->modify('+2 minutes'))
            ->withClaim('channels', [self::CHANNEL])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    public function notify(string $reportId): void
    {
        try {
            $response = $this->http->request('POST', rtrim($this->url, '/') . '/api/publish', [
                'headers' => ['X-API-Key' => $this->apiKey],
                'json' => [
                    'channel' => self::CHANNEL,
                    'data' => ['version' => 1, 'type' => 'report.changed', 'reportId' => $reportId],
                ],
                'timeout' => 2,
                'max_duration' => 3,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
            if ($status < 200 || $status >= 300 || !is_array($body) || isset($body['error']) || !array_key_exists('result', $body)) {
                $this->logger->error('Could not publish report update.', ['report_id' => $reportId, 'http_status' => $status, 'response' => $body]);
            }
        } catch (TransportExceptionInterface|\JsonException $exception) {
            $this->logger->error('Could not publish report update.', ['report_id' => $reportId, 'exception' => $exception]);
        }
    }
}
