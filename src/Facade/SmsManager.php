<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Facade;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Contracts\HttpClientInterface;
use ExcelleInsights\Sms\Contracts\SmsProviderInterface;
use ExcelleInsights\Sms\Controller\WebhookController;
use ExcelleInsights\Sms\Entities\BatchResult;
use ExcelleInsights\Sms\Entities\SendResult;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingConfig;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingProvider;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingResponseMapper;
use ExcelleInsights\Sms\Providers\AfricasTalking\AfricasTalkingWebhookParser;
use ExcelleInsights\Sms\Providers\Celcom\CelcomConfig;
use ExcelleInsights\Sms\Providers\Celcom\CelcomProvider;
use ExcelleInsights\Sms\Providers\Celcom\CelcomResponseMapper;
use ExcelleInsights\Sms\Providers\Log\LogSmsProvider;
use ExcelleInsights\Sms\Providers\ProviderRegistry;
use ExcelleInsights\Sms\Repositories\BatchRepository;
use ExcelleInsights\Sms\Repositories\DeliveryReportRepository;
use ExcelleInsights\Sms\Repositories\InboundMessageRepository;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Repositories\ProviderConfigRepository;
use ExcelleInsights\Sms\Repositories\StatusChangeRepository;
use ExcelleInsights\Sms\Repositories\TemplateRepository;
use ExcelleInsights\Sms\Repositories\TemplateVersionRepository;
use ExcelleInsights\Sms\Repositories\WebhookLogRepository;
use ExcelleInsights\Sms\Services\MessageStatusService;
use ExcelleInsights\Sms\Services\OptOutService;
use ExcelleInsights\Sms\Services\ProviderResolver;
use ExcelleInsights\Sms\Services\SendService;
use ExcelleInsights\Sms\Services\StatusTransitionPolicy;
use ExcelleInsights\Sms\Services\TemplateRenderer;
use ExcelleInsights\Sms\Services\TemplateService;
use ExcelleInsights\Sms\Services\WebhookService;
use ExcelleInsights\Sms\Services\WorkerService;
use ExcelleInsights\Sms\Support\Crypto;
use ExcelleInsights\Sms\Validation\MessageValidator;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;
use ExcelleInsights\Sms\Validation\TemplateValidator;
use PDO;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

class SmsManager
{
    private readonly SmsConfig $config;
    private readonly Crypto $crypto;
    private readonly ProviderConfigRepository $providerConfigRepository;
    private readonly ProviderRegistry $providerRegistry;
    private readonly ProviderResolver $providerResolver;
    private readonly MessageRepository $messageRepository;
    private readonly BatchRepository $batchRepository;
    private readonly TemplateRepository $templateRepository;
    private readonly TemplateVersionRepository $templateVersionRepository;
    private readonly OptOutRepository $optOutRepository;
    private readonly StatusChangeRepository $statusChangeRepository;
    private readonly DeliveryReportRepository $deliveryReportRepository;
    private readonly InboundMessageRepository $inboundMessageRepository;
    private readonly WebhookLogRepository $webhookLogRepository;
    private readonly PhoneNumberValidator $phoneValidator;
    private readonly MessageValidator $messageValidator;
    private readonly TemplateValidator $templateValidator;
    private readonly TemplateRenderer $templateRenderer;
    private readonly TemplateService $templateService;
    private readonly SendService $sendService;
    private readonly MessageStatusService $messageStatusService;
    private readonly WorkerService $workerService;
    private readonly WebhookService $webhookService;
    private readonly OptOutService $optOutService;
    private readonly WebhookController $webhookController;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        private readonly PDO $pdo,
        private readonly HttpClientInterface $httpClient,
        private readonly PsrLoggerInterface $logger,
    ) {
        $this->config = new SmsConfig($config);
        $this->crypto = new Crypto($this->config->encryptionKey());
        $prefix = $this->config->tablePrefix();

        $this->providerConfigRepository = new ProviderConfigRepository($this->pdo, $this->crypto, $prefix);
        $this->providerResolver = new ProviderResolver($this->providerConfigRepository, $this->config);
        $this->providerRegistry = new ProviderRegistry();

        $this->messageRepository = new MessageRepository($this->pdo, $prefix);
        $this->batchRepository = new BatchRepository($this->pdo, $prefix);
        $this->templateRepository = new TemplateRepository($this->pdo, $prefix);
        $this->templateVersionRepository = new TemplateVersionRepository($this->pdo, $prefix);
        $this->optOutRepository = new OptOutRepository($this->pdo, $prefix);
        $this->statusChangeRepository = new StatusChangeRepository($this->pdo, $prefix);
        $this->deliveryReportRepository = new DeliveryReportRepository($this->pdo, $prefix);
        $this->inboundMessageRepository = new InboundMessageRepository($this->pdo, $prefix);
        $this->webhookLogRepository = new WebhookLogRepository($this->pdo, $prefix);

        $this->phoneValidator = new PhoneNumberValidator($this->config->defaultCountry());
        $this->messageValidator = new MessageValidator($this->config->maxSegments());
        $this->templateValidator = new TemplateValidator();
        $this->templateRenderer = new TemplateRenderer();

        $this->templateService = new TemplateService(
            $this->templateRepository,
            $this->templateVersionRepository,
            $this->templateRenderer,
            $this->templateValidator,
        );

        $this->sendService = new SendService(
            $this->config,
            $this->providerResolver,
            $this->providerConfigRepository,
            $this->messageRepository,
            $this->batchRepository,
            $this->optOutRepository,
            $this->templateService,
            $this->phoneValidator,
            $this->messageValidator,
            $this->templateRenderer,
        );

        $this->messageStatusService = new MessageStatusService(
            $this->messageRepository,
            $this->statusChangeRepository,
            new StatusTransitionPolicy(),
        );

        $this->workerService = new WorkerService(
            $this->messageRepository,
            $this->providerRegistry,
            $this->statusChangeRepository,
            $this->logger,
        );

        $this->webhookService = new WebhookService(
            $this->providerRegistry,
            $this->config,
            $this->webhookLogRepository,
            $this->inboundMessageRepository,
            $this->deliveryReportRepository,
            $this->messageRepository,
            $this->optOutRepository,
            $this->phoneValidator,
            $this->logger,
        );

        $this->optOutService = new OptOutService($this->optOutRepository, $this->phoneValidator);
        $this->webhookController = new WebhookController($this->webhookService);

        $this->registerDefaultProviders();
    }

    private function registerDefaultProviders(): void
    {
        $this->providerRegistry->register(new LogSmsProvider($this->logger));
        $this->providerRegistry->register(new AfricasTalkingProvider(
            $this->httpClient,
            new AfricasTalkingResponseMapper(),
            new AfricasTalkingWebhookParser(new AfricasTalkingResponseMapper()),
            function (?int $tenantId): AfricasTalkingConfig {
                $credentials = $this->providerConfigRepository->getCredentials('africastalking', $tenantId);
                $senderId = $this->providerConfigRepository->getSenderId('africastalking', $tenantId);

                return new AfricasTalkingConfig(
                    username: (string) ($credentials['username'] ?? ''),
                    apiKey: (string) ($credentials['api_key'] ?? ''),
                    senderId: $senderId,
                    sandbox: (bool) ($credentials['sandbox'] ?? false),
                );
            },
        ));

        $this->providerRegistry->register(new CelcomProvider(
            $this->httpClient,
            new CelcomResponseMapper(),
            function (?int $tenantId): CelcomConfig {
                $credentials = $this->providerConfigRepository->getCredentials('celcom', $tenantId);
                $senderId = $this->providerConfigRepository->getSenderId('celcom', $tenantId);

                return new CelcomConfig(
                    partnerId: (string) ($credentials['partner_id'] ?? ''),
                    apiKey: (string) ($credentials['api_key'] ?? ''),
                    senderId: $senderId,
                );
            },
        ));
    }

    /**
     * Register a custom provider.
     */
    public function registerProvider(SmsProviderInterface $provider): void
    {
        $this->providerRegistry->register($provider);
    }

    /**
     * Get a provider by key for one-off use.
     */
    public function provider(string $key): SmsProviderInterface
    {
        return $this->providerRegistry->get($key);
    }

    /**
     * Set the default provider for a tenant or globally.
     */
    public function setDefaultProvider(string $key, ?int $tenantId = null): void
    {
        $this->providerConfigRepository->setDefaultProvider($key, $tenantId);
    }

    /**
     * Get the default provider for a tenant or globally.
     */
    public function getDefaultProvider(?int $tenantId = null): ?string
    {
        return $this->providerConfigRepository->getDefaultProvider($tenantId)
            ?? $this->config->defaultProvider();
    }

    /**
     * @return array<int, string>
     */
    public function listProviders(): array
    {
        return $this->providerRegistry->keys();
    }

    /**
     * Resolve the provider that would be used for a send.
     */
    public function resolveProvider(?string $provider = null, ?int $tenantId = null): string
    {
        return $this->providerResolver->resolve($provider, $tenantId);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $to, string $message, array $options = []): SendResult
    {
        return $this->sendService->send($to, $message, $options);
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @param array<string, mixed> $options
     */
    public function sendBulk(array $recipients, string $message, array $options = []): BatchResult
    {
        return $this->sendService->sendBulk($recipients, $message, $options);
    }

    public function templates(): TemplateService
    {
        return $this->templateService;
    }

    public function status(): MessageStatusService
    {
        return $this->messageStatusService;
    }

    public function worker(): WorkerService
    {
        return $this->workerService;
    }

    public function webhooks(): WebhookService
    {
        return $this->webhookService;
    }

    public function optOut(string $phoneNumber, ?int $tenantId = null, ?string $source = null): void
    {
        $this->optOutService->optOut($phoneNumber, $tenantId, $source);
    }

    public function isOptedOut(string $phoneNumber, ?int $tenantId = null): bool
    {
        return $this->optOutService->isOptedOut($phoneNumber, $tenantId);
    }

    public function getWebhookController(): WebhookController
    {
        return $this->webhookController;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderCredentials(string $provider, ?int $tenantId = null): ?array
    {
        return $this->providerConfigRepository->getCredentials($provider, $tenantId);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function setProviderCredentials(string $provider, ?int $tenantId, array $credentials, ?string $senderId = null): void
    {
        $this->providerConfigRepository->saveCredentials($provider, $tenantId, $credentials, $senderId);
    }

    public function getConfig(): SmsConfig
    {
        return $this->config;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    public function getLogger(): PsrLoggerInterface
    {
        return $this->logger;
    }
}
