<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\SmsTemplate;
use ExcelleInsights\Sms\Repositories\BatchRepository;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Repositories\ProviderConfigRepository;
use ExcelleInsights\Sms\Repositories\TemplateRepository;
use ExcelleInsights\Sms\Repositories\TemplateVersionRepository;
use ExcelleInsights\Sms\Services\ProviderResolver;
use ExcelleInsights\Sms\Services\SendService;
use ExcelleInsights\Sms\Services\TemplateRenderer;
use ExcelleInsights\Sms\Services\TemplateService;
use ExcelleInsights\Sms\Support\Crypto;
use ExcelleInsights\Sms\Tests\TestCase;
use ExcelleInsights\Sms\Validation\MessageValidator;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;
use ExcelleInsights\Sms\Validation\TemplateValidator;

final class SendServiceTest extends TestCase
{
    private SendService $sendService;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = 'sms_';
        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $config = new SmsConfig([
            'encryption_key' => base64_encode(random_bytes(32)),
            'default_provider' => 'log',
            'default_country' => 'KE',
            'max_segments' => 6,
            'idempotency_key_ttl_hours' => 24,
        ]);

        $providerConfigRepository = new ProviderConfigRepository($this->pdo, $crypto, $prefix);
        $providerResolver = new ProviderResolver($providerConfigRepository, $config);
        $this->messageRepository = new MessageRepository($this->pdo, $prefix);
        $batchRepository = new BatchRepository($this->pdo, $prefix);
        $optOutRepository = new OptOutRepository($this->pdo, $prefix);
        $templateRepository = new TemplateRepository($this->pdo, $prefix);
        $templateVersionRepository = new TemplateVersionRepository($this->pdo, $prefix);
        $templateService = new TemplateService(
            $templateRepository,
            $templateVersionRepository,
            new TemplateRenderer(),
            new TemplateValidator(),
        );

        $this->sendService = new SendService(
            $config,
            $providerResolver,
            $providerConfigRepository,
            $this->messageRepository,
            $batchRepository,
            $optOutRepository,
            $templateService,
            new PhoneNumberValidator('KE'),
            new MessageValidator(6),
            new TemplateRenderer(),
        );
    }

    public function testSendsSingleMessageAsQueued(): void
    {
        $result = $this->sendService->send('0712345678', 'Hello');

        self::assertSame(MessageStatus::QUEUED, $result->status);
        self::assertNotNull($result->localId);

        $message = $this->messageRepository->findById($result->localId);
        self::assertNotNull($message);
        self::assertSame('+254712345678', $message->to);
        self::assertSame('log', $message->provider);
    }

    public function testSchedulesMessage(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

        $result = $this->sendService->send('0712345678', 'Hello', ['scheduled_at' => $future]);

        self::assertSame(MessageStatus::SCHEDULED, $result->status);
    }

    public function testHoldsMessageForApproval(): void
    {
        $result = $this->sendService->send('0712345678', 'Hello', ['pending' => true]);

        self::assertSame(MessageStatus::PENDING, $result->status);
    }

    public function testBlocksMarketingToOptedOutNumber(): void
    {
        $this->pdo->prepare('INSERT INTO sms_opt_outs (tenant_id, phone_number) VALUES (?, ?)')
            ->execute([7, '+254712345678']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opted out');

        $this->sendService->send('0712345678', 'Marketing message', ['tenant_id' => 7, 'category' => SmsTemplate::CATEGORY_MARKETING]);
    }

    public function testAllowsTransactionalToOptedOutNumber(): void
    {
        $this->pdo->prepare('INSERT INTO sms_opt_outs (tenant_id, phone_number) VALUES (?, ?)')
            ->execute([7, '+254712345678']);

        $result = $this->sendService->send('0712345678', 'Your OTP is 1234', [
            'tenant_id' => 7,
            'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
        ]);

        self::assertSame(MessageStatus::QUEUED, $result->status);
    }

    public function testIdempotencyPreventsDuplicate(): void
    {
        $result1 = $this->sendService->send('0712345678', 'Hello', ['idempotency_key' => 'key-1']);
        $result2 = $this->sendService->send('0712345678', 'Hello', ['idempotency_key' => 'key-1']);

        self::assertSame($result1->localId, $result2->localId);
    }

    public function testSendsFromTemplate(): void
    {
        $templateService = new TemplateService(
            new TemplateRepository($this->pdo, 'sms_'),
            new TemplateVersionRepository($this->pdo, 'sms_'),
            new TemplateRenderer(),
            new TemplateValidator(),
        );

        $templateService->create([
            'tenant_id' => 7,
            'key' => 'invoice',
            'name' => 'Invoice',
            'body' => 'Hi {name}, pay {amount}.',
        ]);

        $result = $this->sendService->send('0712345678', '', [
            'tenant_id' => 7,
            'template' => 'invoice',
            'template_vars' => ['name' => 'Wanjiru', 'amount' => 'KES 500'],
        ]);

        self::assertNotNull($result->localId);
        $message = $this->messageRepository->findById($result->localId);
        self::assertNotNull($message);
        self::assertSame('Hi Wanjiru, pay KES 500.', $message->body);
    }
}
