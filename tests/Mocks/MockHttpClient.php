<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Mocks;

use ExcelleInsights\Sms\Contracts\HttpClientInterface;
use ExcelleInsights\Sms\Contracts\HttpResponse;

class MockHttpClient implements HttpClientInterface
{
    /** @var array<int, array{url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<int, HttpResponse> */
    public array $responses = [];

    private int $callIndex = 0;

    public function queueResponse(HttpResponse $response): void
    {
        $this->responses[] = $response;
    }

    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->recordAndRespond($url, $options);
    }

    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->recordAndRespond($url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function recordAndRespond(string $url, array $options): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'options' => $options];

        if (!isset($this->responses[$this->callIndex])) {
            throw new \RuntimeException('No queued response for request ' . $this->callIndex);
        }

        return $this->responses[$this->callIndex++];
    }
}
