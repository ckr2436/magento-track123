<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Dto;

class ProviderTrackingResult
{
    /**
     * @param ProviderEvent[] $events
     * @param array<string,mixed> $rawPayload
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $trackingNumber,
        public readonly ?string $externalTrackerId,
        public readonly ?string $externalShipmentId,
        public readonly ?string $carrierCode,
        public readonly ?string $carrierName,
        public readonly string $normalizedStatus,
        public readonly ?string $providerStatusCode,
        public readonly ?string $providerStatusCategory,
        public readonly ?string $providerStatusMilestone,
        public readonly ?string $statusLabel,
        public readonly ?string $subStatusLabel,
        public readonly ?string $signedBy,
        public readonly ?string $externalQueryUrl,
        public readonly ?string $lastTrackingTime,
        public readonly ?string $deliveryDate,
        public readonly array $events,
        public readonly array $rawPayload = [],
        public readonly array $meta = []
    ) {
    }
}
