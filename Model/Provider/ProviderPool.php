<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider;

use Pynarae\Tracking\Api\Provider\TrackingProviderInterface;
use Pynarae\Tracking\Exception\UnsupportedProviderException;

class ProviderPool
{
    /**
     * @param array<string,TrackingProviderInterface> $providers
     */
    public function __construct(private readonly array $providers = [])
    {
    }

    public function get(string $code): TrackingProviderInterface
    {
        $code = trim($code);
        if ($code === '' || !isset($this->providers[$code])) {
            throw new UnsupportedProviderException(__('Unsupported tracking provider: %1', $code));
        }

        return $this->providers[$code];
    }

    /**
     * @return array<string,TrackingProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
