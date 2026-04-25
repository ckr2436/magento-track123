<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Kd100;

use Magento\Sales\Model\Order\Shipment\Track;

class Kd100CarrierResolver
{
    public function __construct(private readonly Kd100Config $config)
    {
    }

    public function resolve(Track $track, ?int $storeId = null): ?string
    {
        $mapping = $this->parseMapping($this->config->getCarrierMappingRaw($storeId));
        $candidates = array_filter([
            trim((string)$track->getCarrierCode()),
            trim((string)$track->getTitle()),
            'custom:' . trim((string)$track->getTitle()),
        ]);

        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate);
            if (isset($mapping[$key]) && $mapping[$key] !== '') {
                return $mapping[$key];
            }
        }

        $carrierCode = trim((string)$track->getCarrierCode());
        if ($carrierCode !== '' && !in_array($carrierCode, ['custom', 'track123_custom'], true)) {
            return $carrierCode;
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function parseMapping(string $raw): array
    {
        $mapping = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if ($from !== '' && $to !== '') {
                $mapping[mb_strtolower($from)] = $to;
            }
        }

        return $mapping;
    }
}
