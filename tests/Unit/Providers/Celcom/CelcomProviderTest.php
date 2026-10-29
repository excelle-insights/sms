<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Providers\Celcom;

use ExcelleInsights\Sms\Contracts\HttpResponse;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Providers\Celcom\CelcomConfig;
use ExcelleInsights\Sms\Providers\Celcom\CelcomProvider;
use ExcelleInsights\Sms\Providers\Celcom\CelcomResponseMapper;
use ExcelleInsights\Sms\Tests\Mocks\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class CelcomProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private CelcomProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();

        $this->provider = new CelcomProvider(
            $this->httpClient,
            new CelcomResponseMapper(),
            fn (): CelcomConfig => new CelcomConfig(
                partnerId: 'P123',
                apiKey: 'key',
                senderId: 'SENDER',
            ),
        );
    }

    public function testSendReturnsSentResult(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 200,
            'response-description' => 'Success',
            'responses' => [
                [
                    'respose-code' => 200,
                    'response-description' => 'Success',
                    'mobile' => 254712345678,
                    'messageid' => '8290842',
                    'networkid' => '1',
                ],
            ],
        ])));

        $result = $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));

        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertSame('8290842', $result->providerMessageId);

        $request = $this->httpClient->requests[0];
        self::assertStringContainsString('sendsms', $request['url']);
        self::assertSame('application/json', $request['options']['headers']['Content-Type']);

        $body = json_decode((string) $request['options']['body'], true);
        self::assertSame('254712345678', $body['mobile']);
        self::assertSame('P123', $body['partnerID']);
    }

    public function testSendReturnsQueuedResultForAcceptedRecipientStatus(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 200,
            'response-description' => 'Success',
            'responses' => [
                [
                    'respose-code' => 101,
                    'response-description' => 'Processed',
                    'mobile' => 254712345678,
                    'messageid' => '8290842',
                    'networkid' => '1',
                ],
            ],
        ])));

        $result = $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));

        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::QUEUED, $result->status);
        self::assertSame('8290842', $result->providerMessageId);
    }

    public function testSendThrowsWhenCredentialsAreMissing(): void
    {
        $provider = new CelcomProvider(
            $this->httpClient,
            new CelcomResponseMapper(),
            fn (): CelcomConfig => new CelcomConfig(
                partnerId: '',
                apiKey: '',
                senderId: 'SENDER',
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Celcom credentials are not configured');

        $provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));
    }

    public function testSendThrowsOnPermanentError(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 1003,
            'response-description' => 'Invalid mobile number',
        ])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permanent error');

        $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));
    }

    public function testSendReturnsFailedOnTransientError(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 1005,
            'response-description' => 'Failed - system error',
        ])));

        $result = $this->provider->send(new SendRequest(to: '+254712345678', body: 'Hello'));

        self::assertFalse($result->isSuccess());
        self::assertSame(MessageStatus::FAILED, $result->status);
    }

    public function testSendBulkUsesSmsList(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 200,
            'response-description' => 'Success',
            'responses' => [
                ['respose-code' => 200, 'messageid' => '1'],
                ['respose-code' => 101, 'messageid' => '2'],
            ],
        ])));

        $results = $this->provider->sendBulk([
            new SendRequest(to: '+254712345678', body: 'Hello'),
            new SendRequest(to: '+254723456789', body: 'Hello'),
        ]);

        self::assertCount(2, $results);
        self::assertSame('1', $results[0]->providerMessageId);
        self::assertSame(MessageStatus::QUEUED, $results[1]->status);

        $request = $this->httpClient->requests[0];
        self::assertStringContainsString('sendbulk', $request['url']);

        $body = json_decode((string) $request['options']['body'], true);
        self::assertSame(2, $body['count']);
        self::assertCount(2, $body['smslist']);
    }

    public function testGetBalance(): void
    {
        $this->httpClient->queueResponse(new HttpResponse(200, $this->json([
            'response-code' => 200,
            'response-description' => 'Success',
            'balance' => 1500,
        ])));

        $balance = $this->provider->getBalance();

        self::assertNotNull($balance);
        self::assertSame(1500.0, $balance->amount);
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
