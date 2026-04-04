<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Pynarae\Tracking\Model\Dto\ProviderEvent;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\Provider\Track123\Track123PayloadMapper;
use Pynarae\Tracking\Model\ResourceModel\Cache as CacheResource;
use Pynarae\Tracking\Model\ResourceModel\Cache\CollectionFactory as CacheCollectionFactory;

class TrackingCacheManager
{
    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly CacheResource $cacheResource,
        private readonly CacheCollectionFactory $cacheCollectionFactory,
        private readonly Track123PayloadMapper $track123PayloadMapper,
        private readonly DateTime $dateTime
    ) {
    }

    public function getByTrackId(int $trackId): ?Cache
    {
        $collection = $this->cacheCollectionFactory->create();
        $collection->addFieldToFilter('track_id', $trackId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }

    public function getByTrackingNumber(string $trackingNumber, ?string $providerCode = null): ?Cache
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return null;
        }

        $collection = $this->cacheCollectionFactory->create();
        $collection->addFieldToFilter('tracking_number', $trackingNumber);
        if ($providerCode !== null && trim($providerCode) !== '') {
            $collection->addFieldToFilter('provider_code', trim($providerCode));
        }
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }

    public function isStale(Cache $cache, int $minutes): bool
    {
        $lastSyncedAt = (string)$cache->getData('last_synced_at');
        if ($lastSyncedAt === '') {
            return true;
        }

        return (strtotime($lastSyncedAt) ?: 0) < (time() - ($minutes * 60));
    }

    public function markRegistered(int $trackId, bool $registered = true): Cache
    {
        $cache = $this->getByTrackId($trackId) ?: $this->cacheFactory->create();
        if (!$cache->getId()) {
            $cache->setData('track_id', $trackId);
        }
        $cache->setData('is_registered', $registered ? 1 : 0);
        $cache->setData('last_registered_at', $this->dateTime->gmtDate());
        $this->cacheResource->save($cache);

        return $cache;
    }

    /**
     * @param array<string, mixed> $trackData
     * @param array<string, mixed> $context
     */
    public function upsertFromTrack123Payload(array $trackData, array $context = []): Cache
    {
        $providerResult = $this->track123PayloadMapper->map($trackData);
        return $this->upsertFromProviderResult($providerResult, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function upsertFromProviderResult(ProviderTrackingResult $result, array $context = []): Cache
    {
        $trackId = (int)($context['track_id'] ?? 0);
        $trackingNumber = trim((string)($result->trackingNumber ?: ($context['tracking_number'] ?? '')));

        $cache = $trackId > 0 ? $this->getByTrackId($trackId) : null;
        if (!$cache && $trackingNumber !== '') {
            $cache = $this->getByTrackingNumber($trackingNumber, $result->providerCode);
            if (!$cache) {
                $cache = $this->getByTrackingNumber($trackingNumber);
            }
        }
        if (!$cache) {
            $cache = $this->cacheFactory->create();
            if ($trackId > 0) {
                $cache->setData('track_id', $trackId);
            }
        } elseif ($trackId > 0 && !$cache->getData('track_id')) {
            $cache->setData('track_id', $trackId);
        }

        $events = [];
        foreach ($result->events as $event) {
            if ($event instanceof ProviderEvent) {
                $events[] = $event->toArray();
                continue;
            }
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        $cache->addData([
            'store_id' => $context['store_id'] ?? $cache->getData('store_id'),
            'order_id' => $context['order_id'] ?? $cache->getData('order_id'),
            'order_increment_id' => $context['order_increment_id'] ?? $cache->getData('order_increment_id'),
            'shipment_id' => $context['shipment_id'] ?? $cache->getData('shipment_id'),
            'provider_code' => $result->providerCode,
            'provider_tracker_id' => $result->externalTrackerId,
            'provider_shipment_id' => $result->externalShipmentId,
            'tracking_number' => $trackingNumber !== '' ? $trackingNumber : $cache->getData('tracking_number'),
            'carrier_code' => $result->carrierCode ?? $cache->getData('carrier_code'),
            'carrier_name' => $result->carrierName ?? $cache->getData('carrier_name'),
            'normalized_status' => $result->normalizedStatus,
            'provider_status_code' => $result->providerStatusCode,
            'provider_status_category' => $result->providerStatusCategory,
            'provider_status_milestone' => $result->providerStatusMilestone,
            'transit_status' => $result->providerStatusCode,
            'transit_sub_status' => $result->providerStatusMilestone,
            'signed_by' => $result->signedBy,
            'external_query_url' => $result->externalQueryUrl,
            'carrier_homepage' => $result->meta['carrier_homepage'] ?? $cache->getData('carrier_homepage'),
            'carrier_logo' => $result->meta['carrier_logo'] ?? $cache->getData('carrier_logo'),
            'transit_days' => $result->meta['transit_days'] ?? $cache->getData('transit_days'),
            'stay_days' => $result->meta['stay_days'] ?? $cache->getData('stay_days'),
            'last_tracking_time' => $this->normalizeDate($result->lastTrackingTime),
            'delivery_date' => $this->normalizeDate($result->deliveryDate),
            'is_registered' => 1,
            'last_synced_at' => $this->dateTime->gmtDate(),
            'events_json' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_response_json' => json_encode($result->rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sync_error' => null,
        ]);

        $this->cacheResource->save($cache);

        return $cache;
    }

    public function markError(int $trackId, string $message): void
    {
        $cache = $this->getByTrackId($trackId) ?: $this->cacheFactory->create();
        if (!$cache->getId()) {
            $cache->setData('track_id', $trackId);
        }
        $cache->setData('sync_error', mb_substr($message, 0, 65535));
        $cache->setData('last_synced_at', $this->dateTime->gmtDate());
        $this->cacheResource->save($cache);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
