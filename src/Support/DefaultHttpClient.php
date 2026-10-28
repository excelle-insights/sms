<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

use ExcelleInsights\Sms\Contracts\HttpClientInterface;
use ExcelleInsights\Sms\Contracts\HttpResponse;

class DefaultHttpClient implements HttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $url, array $options): HttpResponse
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise cURL');
        }

        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($headers !== []) {
            $flatHeaders = [];
            foreach ($headers as $key => $value) {
                $flatHeaders[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $flatHeaders);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);

        if ($response === false || !is_string($response)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $bodyString = substr($response, $headerSize);

        return new HttpResponse($statusCode, $bodyString, $this->parseHeaders($rawHeaders));
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
