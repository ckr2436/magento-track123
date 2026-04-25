<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Kd100;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Api\Provider\TrackingProviderInterface;
use Pynarae\Tracking\Model\Dto\ProviderContext;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\Dto\WebhookRequest;

class Kd100Provider implements TrackingProviderInterface
{
    public function __construct(
        private readonly Kd100HttpClient $httpClient,
        private readonly Kd100PayloadMapper $payloadMapper,
        private readonly Kd100CarrierResolver $carrierResolver,
        private readonly Kd100Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'kd100';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isModuleEnabled($storeId) && $this->config->isEnabled($storeId);
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
        $carrierId = $this->resolveCarrierId($context);
        if ($carrierId === null || $carrierId === '') {
            throw new LocalizedException(__('KeyDelivery carrier ID could not be resolved for tracking number %1.', $context->trackingNumber));
        }

        $payload = $this->buildBaseTrackingPayload($context, $carrierId);
        $webhookUrl = $this->config->getWebhookUrl($context->storeId);
        if ($webhookUrl !== '') {
            $payload['webhook_url'] = $webhookUrl;
        }

        $response = $this->httpClient->createTracking($payload, $context->storeId);
        $items = $this->payloadMapper->mapResponseItems($response);

        return $items[0] ?? null;
    }

    public function query(ProviderContext $context): ?ProviderTrackingResult
    {
        $carrierId = $this->resolveCarrierId($context);
        if ($carrierId === null || $carrierId === '') {
            throw new LocalizedException(__('KeyDelivery carrier ID could not be resolved for tracking number %1.', $context->trackingNumber));
        }

        $response = $this->httpClient->realtimeTracking(
            $this->buildBaseTrackingPayload($context, $carrierId),
            $context->storeId
        );

        $items = $this->payloadMapper->mapResponseItems($response);
        return $items[0] ?? null;
    }

    public function verifyWebhook(WebhookRequest $request): bool
    {
        if (!$this->config->isStrictWebhookValidation($request->storeId)) {
            return true;
        }

        // KeyDelivery tracking webhooks are accepted only after local tracking-number ownership
        // validation in the cache manager. Keep strict header validation opt-in until the live
        // KD100 webhook signature headers are confirmed in production.
        $received = trim((string)($request->headers['signature'] ?? ''));
        if ($received === '') {
            return false;
        }

        $apiKey = $this->config->getApiKey($request->storeId);
        $apiSecret = $this->config->getApiSecret($request->storeId);
        $expected = strtoupper(md5($request->rawBody . $apiKey . $apiSecret));

        return hash_equals($expected, $received);
    }

    public function parseWebhook(WebhookRequest $request): array
    {
        if ($request->jsonBody === null) {
            return [];
        }

        return $this->payloadMapper->mapResponseItems($request->jsonBody);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBaseTrackingPayload(ProviderContext $context, string $carrierId): array
    {
        $payload = [
            'carrier_id' => $carrierId,
            'tracking_number' => $context->trackingNumber,
            'area_show' => 1,
            'order' => 'desc',
        ];

        $phone = trim((string)($context->verification['phone_suffix'] ?? ''));
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        $shipFrom = $this->config->getDefaultShipFrom($context->storeId);
        if ($shipFrom !== '') {
            $payload['ship_from'] = $shipFrom;
        }

        $shipTo = $this->buildShipTo($context);
        if ($shipTo !== '') {
            $payload['ship_to'] = $shipTo;
        }

        return $payload;
    }

    private function buildShipTo(ProviderContext $context): string
    {
        $country = trim((string)($context->extra['ship24_destination_country_code'] ?? ''));
        $postal = trim((string)($context->extra['ship24_destination_post_code'] ?? ''));

        return trim(implode(', ', array_filter([$postal, $country])));
    }

    private function resolveCarrierId(ProviderContext $context): ?string
    {
        $track = $context->extra['track'] ?? null;
        if ($track instanceof Track) {
            $mapped = $this->carrierResolver->resolve($track, $context->storeId);
            if ($mapped !== null && $mapped !== '') {
                return $mapped;
            }
        }

        $carrierCode = trim((string)$context->carrierCode);
        if ($carrierCode !== '' && !in_array($carrierCode, ['custom', 'track123_custom'], true)) {
            return $carrierCode;
        }

        if (!$this->config->shouldAutoDetectCarrier($context->storeId)) {
            return null;
        }

        try {
            $response = $this->httpClient->detectCarrier([
                'tracking_number' => $context->trackingNumber,
            ], $context->storeId);
            return $this->extractDetectedCarrierId($response);
        } catch (\Throwable $e) {
            $this->logger->warning('KeyDelivery carrier detection failed', [
                'tracking_number' => $context->trackingNumber,
                'track_id' => $context->trackId,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractDetectedCarrierId(array $response): ?string
    {
        $candidates = [
            $response['carrier_id'] ?? null,
            $response['carrierId'] ?? null,
            $response['data']['carrier_id'] ?? null,
            $response['data']['carrierId'] ?? null,
            $response['data'][0]['carrier_id'] ?? null,
            $response['data'][0]['carrierId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                return trim((string)$candidate);
            }
        }

        $data = $response['data'] ?? null;
        if (is_array($data)) {
            foreach (['items', 'list', 'carriers'] as $key) {
                if (isset($data[$key][0]) && is_array($data[$key][0])) {
                    foreach (['carrier_id', 'carrierId', 'id', 'code'] as $field) {
                        if (isset($data[$key][0][$field]) && trim((string)$data[$key][0][$field]) !== '') {
                            return trim((string)$data[$key][0][$field]);
                        }
                    }
                }
            }
        }

        return null;
    }
}
