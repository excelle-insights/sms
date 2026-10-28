<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\AfricasTalking;

use ExcelleInsights\Sms\Entities\Balance;
use ExcelleInsights\Sms\Entities\DeliveryStatus;

class AfricasTalkingResponseMapper
{
    /**
     * Map a provider status string or code to our normalised delivery status.
     */
    public function mapDeliveryStatus(string $status): DeliveryStatus
    {
        $normalised = strtolower($status);

        return match ($normalised) {
            'delivered', 'success' => DeliveryStatus::DELIVERED,
            'sent', 'buffered', 'submitted' => DeliveryStatus::WITH_CARRIER,
            'failed', 'rejected' => DeliveryStatus::UNDELIVERED,
            'expired' => DeliveryStatus::EXPIRED,
            default => DeliveryStatus::UNKNOWN,
        };
    }

    /**
     * Determine whether an Africa's Talking status code represents a transient
     * error that should be retried.
     */
    public function isTransientError(int $statusCode): bool
    {
        return match ($statusCode) {
            100, 101, 102 => false, // processed / sent / queued
            401, 402, 403, 404, 405, 406, 407 => false, // permanent
            500, 501, 502 => true, // gateway / server errors
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractRecipients(array $response): array
    {
        return $response['SMSMessageData']['Recipients'] ?? [];
    }

    /**
     * @param array<string, mixed> $recipient
     */
    public function isRecipientSuccessful(array $recipient): bool
    {
        $statusCode = (int) ($recipient['statusCode'] ?? 0);

        return in_array($statusCode, [100, 101, 102], true);
    }

    /**
     * @param array<string, mixed> $balanceResponse
     */
    public function mapBalance(array $balanceResponse): ?Balance
    {
        $balanceString = $balanceResponse['UserData']['balance'] ?? null;

        if ($balanceString === null || !is_string($balanceString)) {
            return null;
        }

        $parts = explode(' ', $balanceString);
        $currency = $parts[0] ?? 'USD';
        $amount = isset($parts[1]) ? (float) $parts[1] : 0.0;

        return new Balance(amount: $amount, currency: $currency, rawResponse: $balanceString);
    }
}
