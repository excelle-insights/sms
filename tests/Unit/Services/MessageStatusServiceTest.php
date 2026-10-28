<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\StatusContext;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\StatusChangeRepository;
use ExcelleInsights\Sms\Services\MessageStatusService;
use ExcelleInsights\Sms\Services\StatusTransitionPolicy;
use ExcelleInsights\Sms\Tests\TestCase;

final class MessageStatusServiceTest extends TestCase
{
    private MessageStatusService $service;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = 'sms_';
        $this->messageRepository = new MessageRepository($this->pdo, $prefix);
        $statusChangeRepository = new StatusChangeRepository($this->pdo, $prefix);

        $this->service = new MessageStatusService(
            $this->messageRepository,
            $statusChangeRepository,
            new StatusTransitionPolicy(),
        );
    }

    public function testApprovesPendingMessage(): void
    {
        $message = $this->createMessage(MessageStatus::PENDING);
        self::assertNotNull($message->id);

        $result = $this->service->approve($message->id, new StatusContext(actor: 1));

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::QUEUED, $updated->status);
    }

    public function testCancelScheduledMessage(): void
    {
        $message = $this->createMessage(MessageStatus::SCHEDULED);
        self::assertNotNull($message->id);

        $result = $this->service->cancel($message->id, new StatusContext(actor: 1, reason: 'No longer needed'));

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::CANCELLED, $updated->status);
    }

    public function testRejectsInvalidTransition(): void
    {
        $message = $this->createMessage(MessageStatus::SENT);
        self::assertNotNull($message->id);

        $result = $this->service->cancel($message->id, new StatusContext(actor: 1));

        self::assertFalse($result->isSuccess());
        self::assertSame('invalid_transition', $result->outcome);
    }

    public function testIdempotentTransition(): void
    {
        $message = $this->createMessage(MessageStatus::QUEUED);
        self::assertNotNull($message->id);

        $result = $this->service->retry($message->id, new StatusContext(actor: 1));

        self::assertSame('unchanged', $result->outcome);
    }

    private function createMessage(MessageStatus $status): Message
    {
        $message = $this->messageRepository->create(new Message(
            tenantId: 7,
            provider: 'log',
            to: '+254712345678',
            body: 'Test',
            status: $status,
        ));

        return $message;
    }
}
