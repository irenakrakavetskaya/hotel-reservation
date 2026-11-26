<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Scheduler\MainScheduleProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

final class SchedulerTest extends KernelTestCase
{
    public function testSchedulerTransportGeneratesReportOutboxJob(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.scheduler_default');
        self::assertInstanceOf(SchedulerTransport::class, $transport);

        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $generator = new MessageGenerator(new MainScheduleProvider(new ArrayAdapter()), 'test', $clock);
        iterator_to_array($generator->getMessages(), false);

        $clock->sleep(11);
        $messages = iterator_to_array($generator->getMessages(), false);
        self::assertCount(1, $messages);
        self::assertInstanceOf(RunCommandMessage::class, $messages[0]);
        self::assertSame('app:reports:publish-outbox', $messages[0]->input);
    }
}
