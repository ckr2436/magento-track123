<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Ship24;

use Pynarae\Tracking\Api\Provider\TrackingProviderInterface;
use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\Dto\ProviderContext;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\Dto\WebhookRequest;

class Ship24Provider implements TrackingProviderInterface
{
    public function __construct(
        private readonly Ship24HttpClient $httpClient,
        private readonly Ship24PayloadMapper $payloadMapper,
        private readonly Ship24WebhookVerifier $webhookVerifier,
        private readonly Config $config
    ) {
    }

    public function getCode(): string
    {
        return 'ship24';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isEnabled($storeId) && $this->config->isShip24Enabled($storeId);
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
        $payload = [
            'tracking_number' => $context->trackingNumber,
            'metadata' => [
                'order_increment_id' => $context->orderIncrementId,
            ],
        ];

        if ($this->config->restrictShip24TrackingToCourierCode($context->storeId) && $context->carrierCode !== null) {
            $payload['courier_code'] = $context->carrierCode;
        }

        $this->httpClient->register($payload, $context->storeId);

        if ($this->config->useShip24CreateAndTrackEndpoint($context->storeId)) {
            return $this->query($context);
        }

        return null;
    }

    public function query(ProviderContext $context): ?ProviderTrackingResult
    {
        $payload = [
            'tracking_numbers' => [$context->trackingNumber],
        ];

        if ($this->config->restrictShip24TrackingToCourierCode($context->storeId) && $context->carrierCode !== null) {
            $payload['courier_code'] = $context->carrierCode;
        }

        $response = $this->httpClient->query($payload, $context->storeId);
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

        return $this->payloadMapper->mapResponseItems($request->jsonBody);
    }
}
