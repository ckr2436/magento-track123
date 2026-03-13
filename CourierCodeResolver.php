<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Sales\Model\Order\Shipment\Track;

class CourierCodeResolver
{
    public function __construct(private readonly Config $config)
    {
    }

    public function resolve(Track $track, ?int $storeId = null): ?string
    {
        $mapping = $this->parseMappings($this->config->getCarrierMappingRaw($storeId));

        $candidates = array_filter([
            $track->getCarrierCode(),
            $track->getTitle(),
            'custom:' . (string) $track->getTitle(),
            strtolower((string) $track->getCarrierCode()),
            strtolower((string) $track->getTitle()),
            'custom:' . strtolower((string) $track->getTitle()),
        ]);

        foreach ($candidates as $candidate) {
            $key = trim((string) $candidate);
            if ($key !== '' && isset($mapping[$key])) {
                return $mapping[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function parseMappings(string $raw): array
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$left, $right] = array_map('trim', explode('=', $line, 2));
            if ($left === '' || $right === '') {
                continue;
            }
            $map[$left] = $right;
            $map[strtolower($left)] = $right;
        }

        return $map;
    }
}
