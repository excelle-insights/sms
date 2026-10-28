<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Entities\RenderedMessage;
use ExcelleInsights\Sms\Validation\TemplateValidator;

class TemplateRenderer
{
    private const GSM7_BASIC = [
        '@', '£', '$', '¥', 'è', 'é', 'ù', 'ì', 'ò', 'Ç', '\n', 'Ø', 'ø', '\r', 'Å', 'å',
        'Δ', '_', 'Φ', 'Γ', 'Λ', 'Ω', 'Π', 'Ψ', 'Σ', 'Θ', 'Ξ', 'Æ', 'æ', 'ß', 'É',
        ' ', '!', '"', '#', '¤', '%', '&', "'", '(', ')', '*', '+', ',', '-', '.', '/',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':', ';', '<', '=', '>', '?', '¡',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        'Ä', 'Ö', 'Ñ', 'Ü', '§', '¿',
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
        'ä', 'ö', 'ñ', 'ü', 'à',
    ];

    private const GSM7_EXTENDED = [
        '{', '}', '[', ']', '~', '\\', '^', '|', '€',
    ];

    private readonly TemplateValidator $validator;

    public function __construct()
    {
        $this->validator = new TemplateValidator();
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function render(string $body, array $variables): string
    {
        $rendered = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];

                if (!array_key_exists($key, $variables)) {
                    throw new \InvalidArgumentException("Missing template variable: {$key}");
                }

                return (string) $variables[$key];
            },
            $body,
        );

        if ($rendered === null) {
            throw new \RuntimeException('Failed to render template');
        }

        return $rendered;
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function preview(string $body, array $variables): RenderedMessage
    {
        $rendered = $this->render($body, $variables);

        return $this->analyse($rendered);
    }

    public function analyse(string $body): RenderedMessage
    {
        $encoding = $this->detectEncoding($body);
        $segments = $encoding === 'gsm7'
            ? $this->gsm7Segments($body)
            : $this->ucs2Segments($body);

        return new RenderedMessage(
            body: $body,
            encoding: $encoding,
            segments: $segments,
        );
    }

    /**
     * @return array<int, string>
     */
    public function detectVariables(string $body): array
    {
        return $this->validator->detectVariables($body);
    }

    private function detectEncoding(string $body): string
    {
        $length = mb_strlen($body, 'UTF-8');

        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($body, $i, 1, 'UTF-8');

            if (!in_array($char, self::GSM7_BASIC, true) && !in_array($char, self::GSM7_EXTENDED, true)) {
                return 'ucs2';
            }
        }

        return 'gsm7';
    }

    private function gsm7Segments(string $body): int
    {
        $length = mb_strlen($body, 'UTF-8');
        $count = 0;

        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($body, $i, 1, 'UTF-8');
            $count += in_array($char, self::GSM7_EXTENDED, true) ? 2 : 1;
        }

        if ($count <= 160) {
            return 1;
        }

        return (int) ceil($count / 153);
    }

    private function ucs2Segments(string $body): int
    {
        $length = mb_strlen($body, 'UTF-8');

        if ($length <= 70) {
            return 1;
        }

        return (int) ceil($length / 67);
    }
}
