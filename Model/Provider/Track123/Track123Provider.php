<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
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
        private readonly Track123CarrierResolver $carrierResolver,
        private readonly Config $config,
        private readonly LoggerInterface $logger
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

        $resolvedCourierCode = $this->resolveCourierCode($context);

        if ($resolvedCourierCode !== null && $resolvedCourierCode !== '') {
            $payload[0]['courierCode'] = $resolvedCourierCode;
        }

        try {
            $this->httpClient->register($payload, $context->storeId, $context->verification);
        } catch (AdditionalVerificationRequiredException $e) {
            throw new ProviderActionRequiredException(
                __('Additional shipment verification is required before tracking can be retrieved.'),
                $e->getRequiredFields(),
                $e->getPrefill(),
                $e
            );
        }

        return null;
    }

    private function resolveCourierCode(ProviderContext $context): ?string
    {
        $track = $context->extra['track'] ?? null;

        // 1) Prefer local mapping from the raw Magento track object.
        if ($track instanceof Track) {
            $mappedCode = trim((string)$this->carrierResolver->resolve($track, $context->storeId));
            if ($mappedCode !== '') {
                return $mappedCode;
            }
        }

        // 2) Fallback to existing context carrier if it already looks usable.
        $contextCarrierCode = trim((string)$context->carrierCode);
        if ($contextCarrierCode !== '') {
            return $contextCarrierCode;
        }

        // 3) Auto-detect courier only when enabled.
        if (!$this->config->shouldAutoDetectCarrier($context->storeId)) {
            return null;
        }

        return $this->detectCourierCode($context->trackingNumber, $context->storeId, $context->trackId);
    }

    private function detectCourierCode(string $trackingNumber, ?int $storeId, ?int $trackId): ?string
    {
        $attemptPayloads = [
            ['tracking_number' => $trackingNumber],
            ['trackNo' => $trackingNumber],
        ];

        foreach ($attemptPayloads as $payload) {
            try {
                $detectResponse = $this->httpClient->detectCourier($payload, $storeId);
                $detectedCode = $this->extractDetectedCode($detectResponse);
                if ($detectedCode !== null) {
                    return $detectedCode;
                }
            } catch (\Throwable $e) {
                if ($e instanceof LocalizedException && $this->shouldRetryCourierDetection($e)) {
                    continue;
                }

                $this->logger->warning('Track123 carrier detection failed', [
                    'track_id' => $trackId,
                    'tracking_number' => $trackingNumber,
                    'payload_keys' => array_keys($payload),
                    'exception' => $e,
                ]);

                return null;
            }
        }

        return null;
    }

    private function shouldRetryCourierDetection(LocalizedException $exception): bool
    {
        if ($exception instanceof AdditionalVerificationRequiredException) {
            return true;
        }

        $message = mb_strtolower($exception->getMessage());
        foreach (['api secret is not configured', 'request failed', 'returned invalid json'] as $marker) {
            if (str_contains($message, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractDetectedCode(array $response): ?string
    {
        foreach (['data', 'result'] as $key) {
            $candidate = $response[$key] ?? null;
            if (!is_array($candidate)) {
                continue;
            }

            if (isset($candidate['code']) && is_string($candidate['code'])) {
                return $candidate['code'];
            }

            if (array_is_list($candidate) && isset($candidate[0]['code']) && is_string($candidate[0]['code'])) {
                return $candidate[0]['code'];
            }

            foreach (['items', 'list'] as $nestedKey) {
                if (isset($candidate[$nestedKey][0]['code']) && is_string($candidate[$nestedKey][0]['code'])) {
                    return $candidate[$nestedKey][0]['code'];
                }
            }
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
            throw new ProviderActionRequiredException(
                __('Additional shipment verification is required before tracking can be retrieved.'),
                $e->getRequiredFields(),
                $e->getPrefill(),
                $e
            );
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
