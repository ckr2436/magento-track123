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
        private readonly Track123CarrierResolver $carrierResolver,
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

        $resolvedCourierCode = null;

        if (!empty($context->carrierCode)) {
            $resolvedCourierCode = $context->carrierCode;
        }

        $track = $context->extra['track'] ?? null;
        if ($resolvedCourierCode === null && $track instanceof \Magento\Sales\Model\Order\Shipment\Track) {
            $resolvedCourierCode = $this->carrierResolver->resolve($track, $context->storeId);
        }

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
