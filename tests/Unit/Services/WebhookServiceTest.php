<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Entities\DeliveryStatus;
use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Exceptions\WebhookVerificationException;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingProvider;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingResponseMapper;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingWebhookParser;
use ExcelleInsights\Sms\Providers\ProviderRegistry;
use ExcelleInsights\Sms\Repositories\DeliveryReportRepository;
use ExcelleInsights\Sms\Repositories\InboundMessageRepository;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Repositories\WebhookLogRepository;
use ExcelleInsights\Sms\Services\WebhookService;
use ExcelleInsights\Sms\Support\StderrLogger;
use ExcelleInsights\Sms\Tests\Mocks\MockHttpClient;
use ExcelleInsights\Sms\Tests\TestCase;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;

final class WebhookServiceTest extends TestCase
{
    private WebhookService $service;
    private MessageRepository $messageRepository;
    private OptOutRepository $optOutRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = 'sms_';
        $config = new SmsConfig([
            'encryption_key' => base64_encode(random_bytes(32)),
            'default_country' => 'KE',
            'providers' => [
                'africastalking' => [
                    'webhook_secret' => 'super-secret',
                ],
            ],
        ]);

        $registry = new ProviderRegistry();
        $mapper = new AfricasTalkingResponseMapper();
        $httpClient = new MockHttpClient();

        $registry->register(new AfricasTalkingProvider(
            $httpClient,
            $mapper,
            new AfricasTalkingWebhookParser($mapper),
            fn (): \ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingConfig => new \ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingConfig(
                username: 'test',
                apiKey: 'key',
            ),
        ));

        $this->messageRepository = new MessageRepository($this->pdo, $prefix);
        $this->optOutRepository = new OptOutRepository($this->pdo, $prefix);

        $this->service = new WebhookService(
            $registry,
            $config,
            new WebhookLogRepository($this->pdo, $prefix),
            new InboundMessageRepository($this->pdo, $prefix),
            new DeliveryReportRepository($this->pdo, $prefix),
            $this->messageRepository,
            $this->optOutRepository,
            new PhoneNumberValidator('KE'),
            new StderrLogger(),
        );
    }

    public function testDeliveryReportUpdatesMessageStatus(): void
    {
        $message = $this->messageRepository->create(new Message(
            tenantId: 7,
            provider: 'africastalking',
            to: '+254712345678',
            body: 'Test',
            status: MessageStatus::SENT,
            providerMessageId: 'ATXid_123',
        ));
        self::assertNotNull($message->id);

        $this->service->process(
            'africastalking',
            ['query' => ['secret' => 'super-secret']],
            http_build_query(['id' => 'ATXid_123', 'status' => 'Delivered', 'phoneNumber' => '+254712345678']),
        );

        $updated = $this->messageRepository->findById($message->id);
        self::assertNotNull($updated);
        self::assertSame(DeliveryStatus::DELIVERED, $updated->deliveryStatus);
    }

    public function testInboundMessageCreatesOptOut(): void
    {
        $this->service->process(
            'africastalking',
            ['query' => ['secret' => 'super-secret']],
            http_build_query([
                'from' => '+254712345678',
                'to' => 'TEST_SENDER',
                'text' => 'STOP',
                'id' => 'inbound_1',
            ]),
        );

        self::assertTrue($this->optOutRepository->exists('+254712345678', null));
    }

    public function testRejectsInvalidSecret(): void
    {
        $this->expectException(WebhookVerificationException::class);

        $this->service->process(
            'africastalking',
            ['query' => ['secret' => 'wrong-secret']],
            http_build_query(['id' => 'ATXid_123', 'status' => 'Delivered']),
        );
    }
}
