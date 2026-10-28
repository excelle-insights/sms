<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Entities\RenderedMessage;
use ExcelleInsights\Sms\Entities\SmsTemplate;
use ExcelleInsights\Sms\Exceptions\TemplateNotFoundException;
use ExcelleInsights\Sms\Repositories\TemplateRepository;
use ExcelleInsights\Sms\Repositories\TemplateVersionRepository;
use ExcelleInsights\Sms\Validation\TemplateValidator;

class TemplateService
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly TemplateVersionRepository $templateVersionRepository,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateValidator $templateValidator,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SmsTemplate
    {
        $body = (string) ($data['body'] ?? '');
        $this->templateValidator->validate($body);

        $template = new SmsTemplate(
            tenantId: $data['tenant_id'] ?? null,
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            body: '',
            category: (string) ($data['category'] ?? SmsTemplate::CATEGORY_MARKETING),
            status: (string) ($data['status'] ?? SmsTemplate::STATUS_ACTIVE),
            senderId: $data['sender_id'] ?? null,
        );

        $created = $this->templateRepository->create($template);

        if ($created->id === null) {
            throw new \RuntimeException('Failed to create template');
        }

        $variables = $this->templateRenderer->detectVariables($body);

        $this->templateVersionRepository->create(
            $created->id,
            1,
            $body,
            $this->buildVariableDefinitions($variables, $data['variables'] ?? []),
            $data['author'] ?? null,
        );

        return $created;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $templateId, array $data): SmsTemplate
    {
        $existing = $this->templateRepository->findById($templateId);
        $body = isset($data['body']) ? (string) $data['body'] : null;

        if ($body !== null) {
            $this->templateValidator->validate($body);
        }

        $updated = $this->templateRepository->update(new SmsTemplate(
            id: $existing->id,
            tenantId: $existing->tenantId,
            key: $existing->key,
            name: (string) ($data['name'] ?? $existing->name),
            body: '',
            category: (string) ($data['category'] ?? $existing->category),
            status: (string) ($data['status'] ?? $existing->status),
            version: $existing->version + 1,
            senderId: $data['sender_id'] ?? $existing->senderId,
        ));

        if ($updated->id === null) {
            throw new \RuntimeException('Failed to update template');
        }

        if ($body !== null) {
            $variables = $this->templateRenderer->detectVariables($body);

            $this->templateVersionRepository->create(
                $updated->id,
                $updated->version,
                $body,
                $this->buildVariableDefinitions($variables, $data['variables'] ?? []),
                $data['author'] ?? null,
            );
        }

        return $updated;
    }

    public function get(string $key, ?int $tenantId): SmsTemplate
    {
        $template = $this->templateRepository->findByKey($key, $tenantId);

        if ($template === null || $template->status === SmsTemplate::STATUS_ARCHIVED) {
            throw new TemplateNotFoundException($key);
        }

        return $this->hydrateBody($template);
    }

    /**
     * @return array<int, SmsTemplate>
     */
    public function list(?int $tenantId, ?string $status = null): array
    {
        $templates = $this->templateRepository->list($tenantId, $status);

        return array_map([$this, 'hydrateBody'], $templates);
    }

    public function archive(int $id): void
    {
        $this->templateRepository->archive($id);
    }

    public function activate(int $id): void
    {
        $this->templateRepository->activate($id);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function preview(string $key, array $variables, ?int $tenantId): RenderedMessage
    {
        $template = $this->get($key, $tenantId);

        return $this->templateRenderer->preview($template->body, $variables);
    }

    public function validate(string $body): void
    {
        $this->templateValidator->validate($body);
    }

    private function hydrateBody(SmsTemplate $template): SmsTemplate
    {
        if ($template->id === null) {
            throw new \RuntimeException('Cannot hydrate template without id');
        }

        $version = $this->templateVersionRepository->get($template->id, $template->version);

        return new SmsTemplate(
            id: $template->id,
            tenantId: $template->tenantId,
            key: $template->key,
            name: $template->name,
            body: $version === null ? '' : (string) $version['body'],
            category: $template->category,
            status: $template->status,
            version: $template->version,
            variables: $version === null ? [] : (array) $version['variables'],
            senderId: $template->senderId,
        );
    }

    /**
     * @param array<int, string> $detected
     * @param array<string, mixed> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildVariableDefinitions(array $detected, array $definitions): array
    {
        $result = [];

        foreach ($detected as $name) {
            $result[$name] = array_merge(
                ['required' => true, 'default' => null],
                $definitions[$name] ?? [],
            );
        }

        return $result;
    }
}
