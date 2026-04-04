<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider;

use Pynarae\Tracking\Api\Provider\ProviderResolverInterface;
use Pynarae\Tracking\Api\Provider\TrackingProviderInterface;
use Pynarae\Tracking\Model\Config;

class ProviderResolver implements ProviderResolverInterface
{
    public function __construct(
        private readonly ProviderPool $providerPool,
        private readonly Config $config
    ) {
    }

    public function resolveByCode(string $providerCode): TrackingProviderInterface
    {
        return $this->providerPool->get($providerCode);
    }

    public function resolveByStore(?int $storeId = null): TrackingProviderInterface
    {
        return $this->providerPool->get($this->config->getDefaultProvider($storeId));
    }
}
