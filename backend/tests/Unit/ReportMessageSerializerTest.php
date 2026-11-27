<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Messenger\ReportMessageSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class ReportMessageSerializerTest extends TestCase
{
    public function testMalformedJsonThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new ReportMessageSerializer())->decode(['body' => '{']);
    }

    public function testUnsupportedEventThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new ReportMessageSerializer())->decode(['body' => '{"event":"report.generate.v1"}']);
    }

    public function testInvalidReportResultThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new ReportMessageSerializer())->decode(['body' => '{"event":"report.result.v1","report_id":"invalid","status":"done"}']);
    }
}
