<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class SmsTemplate
{
    public const CATEGORY_MARKETING = 'marketing';
    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly string $key = '',
        public readonly string $name = '',
        public readonly string $body = '',
        public readonly string $category = self::CATEGORY_MARKETING,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly int $version = 1,
        public readonly array $variables = [],
        public readonly ?string $senderId = null,
    ) {
    }

    public function isMarketing(): bool
    {
        return $this->category === self::CATEGORY_MARKETING;
    }
}
