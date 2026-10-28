<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testRendersVariables(): void
    {
        $rendered = $this->renderer->render('Hi {name}, ref {ref}', ['name' => 'Wanjiru', 'ref' => 'INV-1']);

        self::assertSame('Hi Wanjiru, ref INV-1', $rendered);
    }

    public function testThrowsOnMissingVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->renderer->render('Hi {name}', []);
    }

    public function testDetectsGsm7Encoding(): void
    {
        $preview = $this->renderer->preview('Hello world', []);

        self::assertSame('gsm7', $preview->encoding);
        self::assertSame(1, $preview->segments);
    }

    public function testDetectsUcs2Encoding(): void
    {
        $preview = $this->renderer->preview('Hello 世界', []);

        self::assertSame('ucs2', $preview->encoding);
    }

    public function testCalculatesGsm7MultipartSegments(): void
    {
        $body = str_repeat('a', 170);
        $preview = $this->renderer->preview($body, []);

        self::assertSame('gsm7', $preview->encoding);
        self::assertSame(2, $preview->segments);
    }
}
