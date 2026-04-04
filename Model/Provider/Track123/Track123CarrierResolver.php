<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Magento\Sales\Model\Order\Shipment\Track;
use Pynarae\Tracking\Model\CourierCodeResolver;

class Track123CarrierResolver
{
    public function __construct(private readonly CourierCodeResolver $courierCodeResolver)
    {
    }

    public function resolve(Track $track, ?int $storeId = null): ?string
    {
        return $this->courierCodeResolver->resolve($track, $storeId);
    }
}
