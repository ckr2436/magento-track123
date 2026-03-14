<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

class Track123PayloadExtractor
{
    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    public function extractTrackingItems(array $response): array
    {
        $candidates = [];

        foreach (['data', 'result', 'items', 'list', 'accepted'] as $key) {
            if (isset($response[$key])) {
                $candidates[] = $response[$key];
            }
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                if ($this->looksLikeSingleTracking($candidate)) {
                    return [$candidate];
                }
                if (array_is_list($candidate)) {
                    return array_values(array_filter($candidate, 'is_array'));
                }
                foreach (['items', 'list', 'trackings', 'content', 'accepted'] as $nestedKey) {
                    if (isset($candidate[$nestedKey]) && is_array($candidate[$nestedKey])) {
                        return array_values(array_filter($candidate[$nestedKey], 'is_array'));
                    }
                }

                if (isset($candidate['accepted']['content']) && is_array($candidate['accepted']['content'])) {
                    return array_values(array_filter($candidate['accepted']['content'], 'is_array'));
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function looksLikeSingleTracking(array $payload): bool
    {
        return isset($payload['trackingNumber'])
            || isset($payload['trackNo'])
            || isset($payload['transitStatus'])
            || isset($payload['trackInfo']);
    }
}
