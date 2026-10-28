<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Validation;

class TemplateValidator
{
    /**
     * @return array<int, string>
     */
    public function detectVariables(string $body): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function validate(string $body): void
    {
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Template body cannot be empty');
        }

        if (preg_match('/\{[^{}]*\}/', $body) === 0 && preg_match('/\{\{|\}\}/', $body) === 1) {
            throw new \InvalidArgumentException('Template body contains malformed placeholders');
        }
    }
}
