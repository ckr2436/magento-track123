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
        $payload = $this->buildTrackerPayload($context);

        if ($this->config->useShip24CreateAndTrackEndpoint($context->storeId)) {
            $response = $this->httpClient->query($payload, $context->storeId);
            $items = $this->payloadMapper->mapResponseItems($response);
            return $items[0] ?? null;
        }

        $this->httpClient->register($payload, $context->storeId);
        return null;
    }

    public function query(ProviderContext $context): ?ProviderTrackingResult
    {
        $payload = $this->buildTrackerPayload($context);
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

    /**
     * Build payload according to Ship24 tracker-create-request schema.
     *
     * @return array<string, mixed>
     */
    private function buildTrackerPayload(ProviderContext $context): array
    {
        $payload = [
            'trackingNumber' => $context->trackingNumber,
            'shipmentReference' => $this->buildShipmentReference($context),
            'clientTrackerId' => $this->buildClientTrackerId($context),
            'orderNumber' => $context->orderIncrementId,
        ];

        $destinationCountryCode = trim((string)($context->extra['ship24_destination_country_code'] ?? ''));
        if ($destinationCountryCode !== '') {
            $payload['destinationCountryCode'] = $destinationCountryCode;
        }

        $destinationPostCode = trim((string)($context->extra['ship24_destination_post_code'] ?? ''));
        if ($destinationPostCode !== '') {
            $payload['destinationPostCode'] = $destinationPostCode;
        }

        $shippingDate = $this->normalizeShip24Date($context->extra['ship24_shipping_date'] ?? null);
        if ($shippingDate !== null) {
            $payload['shippingDate'] = $shippingDate;
        }

        $carrierCode = trim((string)$context->carrierCode);
        if ($carrierCode !== '') {
            $payload['courierCode'] = [$carrierCode];
            $payload['settings'] = [
                'restrictTrackingToCourierCode' => $this->config->restrictShip24TrackingToCourierCode($context->storeId),
            ];
        }

        $carrierTitle = trim((string)$context->carrierTitle);
        if ($carrierTitle !== '') {
            $payload['courierName'] = $carrierTitle;
        }

        return $payload;
    }

    private function buildShipmentReference(ProviderContext $context): string
    {
        if ($context->trackId !== null && $context->trackId > 0) {
            return $context->orderIncrementId . ':' . $context->trackId;
        }

        return $context->orderIncrementId . ':' . substr(
            hash('sha256', $context->providerCode . '|' . $context->orderIncrementId . '|' . $context->trackingNumber),
            0,
            16
        );
    }

    private function buildClientTrackerId(ProviderContext $context): string
    {
        if ($context->trackId !== null && $context->trackId > 0) {
            return 'magento-track-' . $context->trackId;
        }

        return 'magento-' . substr(
            hash('sha256', $context->providerCode . '|' . $context->orderIncrementId . '|' . $context->trackingNumber),
            0,
            24
        );
    }

    /**
     * Ship24 accepts logistics date-time. We send UTC ISO 8601 when possible.
     */
    private function normalizeShip24Date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }
}
