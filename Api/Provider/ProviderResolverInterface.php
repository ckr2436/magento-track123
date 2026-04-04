<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Api\Provider;

interface ProviderResolverInterface
{
    public function resolveByCode(string $providerCode): TrackingProviderInterface;

    public function resolveByStore(?int $storeId = null): TrackingProviderInterface;
}
