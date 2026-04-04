<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Dto;

class ProviderContext
{
    public function __construct(
        public readonly string $providerCode,
        public readonly int $storeId,
        public readonly ?int $orderId,
        public readonly ?int $shipmentId,
        public readonly ?int $trackId,
        public readonly string $orderIncrementId,
        public readonly string $trackingNumber,
        public readonly ?string $carrierCode = null,
        public readonly ?string $carrierTitle = null,
        public readonly array $verification = [],
        public readonly array $extra = []
    ) {
    }
}
