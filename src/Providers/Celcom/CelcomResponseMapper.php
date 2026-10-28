<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\Celcom;

use ExcelleInsights\Sms\Entities\Balance;

class CelcomResponseMapper
{
    /**
     * @param array<string, mixed> $response
     */
    public function isSuccessful(array $response): bool
    {
        return (int) ($response['response-code'] ?? $response['respose-code'] ?? 500) === 200;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function getErrorMessage(array $response): string
    {
        return (string) ($response['response-description'] ?? $response['response-description'] ?? 'Unknown Celcom error');
    }

    /**
     * Determine whether a Celcom response code represents a transient error
     * that should be retried.
     */
    public function isTransientErrorCode(int $code): bool
    {
        return match ($code) {
            200 => false,
            1005, 1007, 4090, 500 => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractRecipients(array $response): array
    {
        return $response['responses'] ?? [];
    }

    /**
     * @param array<string, mixed> $recipient
     */
    public function isRecipientSuccessful(array $recipient): bool
    {
        return (int) ($recipient['respose-code'] ?? $recipient['response-code'] ?? 500) === 200;
    }

    /**
     * @param array<string, mixed> $recipient
     */
    public function getMessageId(array $recipient): ?string
    {
        return isset($recipient['messageid']) ? (string) $recipient['messageid'] : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function mapBalance(array $response): ?Balance
    {
        $balance = $response['balance'] ?? $response['credit'] ?? null;

        if ($balance === null) {
            return null;
        }

        $rawResponse = json_encode($response);

        if (is_numeric($balance)) {
            return new Balance(amount: (float) $balance, currency: 'SMS', rawResponse: $rawResponse === false ? null : $rawResponse);
        }

        $parts = explode(' ', (string) $balance);
        $currency = $parts[0] ?? 'SMS';
        $amount = isset($parts[1]) ? (float) $parts[1] : 0.0;

        return new Balance(amount: $amount, currency: $currency, rawResponse: $rawResponse === false ? null : $rawResponse);
    }
}
