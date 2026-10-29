<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Providers\AfricasTalking;

use ExcelleInsights\Sms\Contracts\HttpResponse;
use ExcelleInsights\Sms\Entities\DeliveryStatus;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingConfig;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingProvider;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingResponseMapper;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingWebhookParser;
use ExcelleInsights\Sms\Tests\Mocks\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class AfricasTalkingProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private AfricasTalkingProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $mapper = new AfricasTalkingResponseMapper();

        $this->provider = new AfricasTalkingProvider(
            $this->httpClient,
            $mapper,
            new AfricasTalkingWebhookParser($mapper),
            fn (): AfricasTalkingConfig => new AfricasTalkingConfig(
                username: 'test_user',
                apiKey: 'test_key',
                senderId: 'TEST_SENDER',
            ),
        );
    }

    public function testSendReturnsSentResult(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'SMSMessageData' => [
                'Message' => 'Sent to 1/1 recipients',
                'Recipients' => [
                    [
                        'statusCode' => 101,
                        'number' => '+254712345678',
                        'status' => 'Sent',
                        'cost' => 'KES 0.8000',
                        'messageId' => 'ATXid_123',
                    ],
                ],
            ],
        ])));

        $result = $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));

        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertSame('ATXid_123', $result->providerMessageId);

        $request = $this->httpClient->requests[0];
        self::assertStringContainsString('messaging', $request['url']);
        self::assertSame('test_key', $request['options']['headers']['apiKey']);
    }

    public function testSendThrowsWhenCredentialsAreMissing(): void
    {
        $provider = new AfricasTalkingProvider(
            $this->httpClient,
            new AfricasTalkingResponseMapper(),
            new AfricasTalkingWebhookParser(new AfricasTalkingResponseMapper()),
            fn (): AfricasTalkingConfig => new AfricasTalkingConfig(
                username: '',
                apiKey: '',
                senderId: 'TEST_SENDER',
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Africa's Talking credentials are not configured");

        $provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));
    }

    public function testSendThrowsOnPermanentError(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'SMSMessageData' => [
                'Recipients' => [
                    [
                        'statusCode' => 403,
                        'number' => '+254712345678',
                        'status' => 'InvalidPhoneNumber',
                    ],
                ],
            ],
        ])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permanent error');

        $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));
    }

    public function testSendReturnsFailedOnTransientError(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'SMSMessageData' => [
                'Recipients' => [
                    [
                        'statusCode' => 500,
                        'number' => '+254712345678',
                        'status' => 'InternalServerError',
                    ],
                ],
            ],
        ])));

        $result = $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));

        self::assertFalse($result->isSuccess());
        self::assertSame(MessageStatus::FAILED, $result->status);
    }

    public function testSendBulkUsesCommaSeparatedRecipients(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'SMSMessageData' => [
                'Recipients' => [
                    ['statusCode' => 101, 'number' => '+254712345678', 'messageId' => 'ATXid_1'],
                    ['statusCode' => 101, 'number' => '+254723456789', 'messageId' => 'ATXid_2'],
                ],
            ],
        ])));

        $results = $this->provider->sendBulk([
            new SendRequest(to: '+254712345678', body: 'Hello'),
            new SendRequest(to: '+254723456789', body: 'Hello'),
        ]);

        self::assertCount(2, $results);
        self::assertSame('ATXid_1', $results[0]->providerMessageId);

        $request = $this->httpClient->requests[0];
        parse_str((string) $request['options']['body'], $body);
        self::assertSame('+254712345678,+254723456789', $body['to']);
    }

    public function testGetBalance(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'UserData' => ['balance' => 'KES 1234.5600'],
        ])));

        $balance = $this->provider->getBalance();

        self::assertNotNull($balance);
        self::assertSame('KES', $balance->currency);
        self::assertSame(1234.56, $balance->amount);
    }

    public function testParseDeliveryReport(): void
    {
        $report = $this->provider->parseDeliveryReport([], http_build_query([
            'id' => 'ATXid_123',
            'status' => 'Delivered',
            'phoneNumber' => '+254712345678',
        ]));

        self::assertNotNull($report);
        self::assertSame('ATXid_123', $report->providerMessageId);
        self::assertSame(DeliveryStatus::DELIVERED, $report->status);
    }

    public function testParseInbound(): void
    {
        $inbound = $this->provider->parseInbound([], http_build_query([
            'from' => '+254712345678',
            'to' => 'TEST_SENDER',
            'text' => 'STOP',
            'id' => 'inbound_1',
            'date' => '2026-10-28 10:00:00',
        ]));

        self::assertNotNull($inbound);
        self::assertSame('+254712345678', $inbound->from);
        self::assertSame('STOP', $inbound->text);
        self::assertSame('africastalking', $inbound->provider);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        $json = json_encode($data);
        self::assertNotFalse($json);

        return $json;
    }
}
