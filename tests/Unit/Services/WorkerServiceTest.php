<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Providers\Log\LogSmsProvider;
use ExcelleInsights\Sms\Providers\ProviderRegistry;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\StatusChangeRepository;
use ExcelleInsights\Sms\Services\WorkerService;
use ExcelleInsights\Sms\Support\StderrLogger;
use ExcelleInsights\Sms\Tests\TestCase;

final class WorkerServiceTest extends TestCase
{
    private WorkerService $worker;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = 'sms_';
        $this->messageRepository = new MessageRepository($this->pdo, $prefix);
        $statusChangeRepository = new StatusChangeRepository($this->pdo, $prefix);
        $registry = new ProviderRegistry();
        $registry->register(new LogSmsProvider(new StderrLogger()));

        $this->worker = new WorkerService(
            $this->messageRepository,
            $registry,
            $statusChangeRepository,
            new StderrLogger(),
        );
    }

    public function testDispatchesQueuedMessage(): void
    {
        $message = $this->createMessage(MessageStatus::QUEUED);
        self::assertNotNull($message->id);

        $dispatched = $this->worker->dispatchQueuedMessages(10);

        self::assertSame(1, $dispatched);

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertSame(MessageStatus::SENT, $updated->status);
        self::assertNotNull($updated->providerMessageId);
    }

    public function testDispatchesHighPriorityMessagesFirst(): void
    {
        $low = $this->createMessage(MessageStatus::QUEUED, priority: 5);
        $high = $this->createMessage(MessageStatus::QUEUED, priority: 1);

        $this->worker->dispatchQueuedMessages(1);

        $highUpdated = $this->messageRepository->findById((int) $high->id);
        $lowUpdated = $this->messageRepository->findById((int) $low->id);

        self::assertNotNull($highUpdated);
        self::assertNotNull($lowUpdated);
        self::assertSame(MessageStatus::SENT, $highUpdated->status);
        self::assertSame(MessageStatus::QUEUED, $lowUpdated->status);
    }

    public function testReleasesDueScheduledMessages(): void
    {
        $message = $this->createMessage(MessageStatus::SCHEDULED, (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'));
        self::assertNotNull($message->id);

        $released = $this->worker->releaseDueScheduledMessages(10);

        self::assertSame(1, $released);

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertSame(MessageStatus::QUEUED, $updated->status);
    }

    public function testRetriesFailedMessageAfterDelay(): void
    {
        $message = $this->messageRepository->create(new Message(
            tenantId: 7,
            provider: 'log',
            to: '+254712345678',
            body: 'Test',
            status: MessageStatus::FAILED,
            attempts: 1,
        ));
        self::assertNotNull($message->id);

        // Force updated_at into the past so retry delay has passed.
        $this->pdo->prepare("UPDATE sms_messages SET updated_at = datetime('now', '-5 minutes') WHERE id = ?")
            ->execute([$message->id]);

        $retried = $this->worker->retryFailedMessages(5, [1, 2, 4, 8, 16], 10);

        self::assertSame(1, $retried);

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertSame(MessageStatus::QUEUED, $updated->status);
        self::assertSame(2, $updated->attempts);
    }

    private function createMessage(MessageStatus $status, ?string $scheduledAt = null, int $priority = 5): Message
    {
        return $this->messageRepository->create(new Message(
            tenantId: 7,
            provider: 'log',
            to: '+254712345678',
            body: 'Test',
            status: $status,
            scheduledAt: $scheduledAt === null ? null : new \DateTimeImmutable($scheduledAt),
            priority: $priority,
        ));
    }
}
