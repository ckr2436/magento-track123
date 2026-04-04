<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Pynarae\Tracking\Api\Provider\TrackingProviderInterface;
use Pynarae\Tracking\Exception\ProviderActionRequiredException;
use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\Dto\ProviderContext;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\Dto\WebhookRequest;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;

class Track123Provider implements TrackingProviderInterface
{
    public function __construct(
        private readonly Track123HttpClient $httpClient,
        private readonly Track123PayloadMapper $payloadMapper,
        private readonly Track123WebhookVerifier $webhookVerifier,
        private readonly Config $config
    ) {
    }

    public function getCode(): string
    {
        return 'track123';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isEnabled($storeId);
    }

    public function supportsRegistration(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function register(ProviderContext $context): ?ProviderTrackingResult
    {
        $payload = [[
            'trackNo' => $context->trackingNumber,
            'orderNo' => $context->orderIncrementId,
        ]];

        if (!empty($context->carrierCode)) {
            $payload[0]['courierCode'] = $context->carrierCode;
        }

        try {
            $this->httpClient->register($payload, $context->storeId, $context->verification);
        } catch (AdditionalVerificationRequiredException $e) {
            throw new ProviderActionRequiredException($e->getRawMessage(), $e->getRequiredFields(), $e->getPrefill(), $e);
        }

        return null;
    }

    public function query(ProviderContext $context): ?ProviderTrackingResult
    {
        $payload = [
            'trackNoInfos' => [
                ['trackNo' => $context->trackingNumber],
            ],
            'orderNos' => [$context->orderIncrementId],
            'cursor' => '',
            'queryPageSize' => 100,
        ];

        try {
            $response = $this->httpClient->query($payload, $context->storeId, $context->verification);
        } catch (AdditionalVerificationRequiredException $e) {
            throw new ProviderActionRequiredException($e->getRawMessage(), $e->getRequiredFields(), $e->getPrefill(), $e);
        }

        $items = $this->payloadMapper->mapResponseItems($response);
        return $items[0] ?? null;
    }

    public function verifyWebhook(WebhookRequest $request): bool
    {
        return $this->webhookVerifier->verify($request);
    }

    public function parseWebhook(WebhookRequest $request): array
    {
        if ($request->jsonBody === null) {
            return [];
        }

        if (isset($request->jsonBody['trackingNumber']) || isset($request->jsonBody['trackNo'])) {
            return [$this->payloadMapper->map($request->jsonBody)];
        }

        return $this->payloadMapper->mapResponseItems($request->jsonBody);
    }
}
