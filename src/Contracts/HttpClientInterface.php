<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Contracts;

interface HttpClientInterface
{
    /**
     * Perform a GET request.
     *
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse;

    /**
     * Perform a POST request.
     *
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): HttpResponse;
}
