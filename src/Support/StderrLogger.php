<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

use Psr\Log\AbstractLogger;

class StderrLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] %s: %s %s' . PHP_EOL,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $message,
            $context === [] ? '' : json_encode($context),
        );

        fwrite(STDERR, $line);
    }
}
