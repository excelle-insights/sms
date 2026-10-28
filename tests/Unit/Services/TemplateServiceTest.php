<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Exceptions\TemplateNotFoundException;
use ExcelleInsights\Sms\Repositories\TemplateRepository;
use ExcelleInsights\Sms\Repositories\TemplateVersionRepository;
use ExcelleInsights\Sms\Services\TemplateRenderer;
use ExcelleInsights\Sms\Services\TemplateService;
use ExcelleInsights\Sms\Tests\TestCase;
use ExcelleInsights\Sms\Validation\TemplateValidator;

final class TemplateServiceTest extends TestCase
{
    private TemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = 'sms_';
        $templateRepository = new TemplateRepository($this->pdo, $prefix);
        $templateVersionRepository = new TemplateVersionRepository($this->pdo, $prefix);

        $this->service = new TemplateService(
            $templateRepository,
            $templateVersionRepository,
            new TemplateRenderer(),
            new TemplateValidator(),
        );
    }

    public function testCreatesTemplateWithVersion(): void
    {
        $template = $this->service->create([
            'tenant_id' => 7,
            'key' => 'invoice_due',
            'name' => 'Invoice due',
            'body' => 'Hi {name}, invoice {ref} is due.',
        ]);

        self::assertSame('invoice_due', $template->key);
        self::assertSame(1, $template->version);

        $hydrated = $this->service->get('invoice_due', 7);
        self::assertSame('Hi {name}, invoice {ref} is due.', $hydrated->body);
    }

    public function testTenantTemplateOverridesGlobal(): void
    {
        $this->service->create([
            'key' => 'greeting',
            'name' => 'Global greeting',
            'body' => 'Hello',
        ]);

        $this->service->create([
            'tenant_id' => 7,
            'key' => 'greeting',
            'name' => 'Tenant greeting',
            'body' => 'Hello {name}',
        ]);

        $template = $this->service->get('greeting', 7);

        self::assertSame('Hello {name}', $template->body);
    }

    public function testThrowsForArchivedTemplate(): void
    {
        $template = $this->service->create([
            'key' => 'old',
            'name' => 'Old',
            'body' => 'Old body',
        ]);

        self::assertNotNull($template->id);
        $this->service->archive($template->id);

        $this->expectException(TemplateNotFoundException::class);

        $this->service->get('old', null);
    }

    public function testPreviewRendersTemplate(): void
    {
        $this->service->create([
            'tenant_id' => 7,
            'key' => 'reminder',
            'name' => 'Reminder',
            'body' => 'Hi {name}, pay {amount}.',
        ]);

        $preview = $this->service->preview('reminder', ['name' => 'Wanjiru', 'amount' => 'KES 500'], 7);

        self::assertSame('Hi Wanjiru, pay KES 500.', $preview->body);
    }
}
