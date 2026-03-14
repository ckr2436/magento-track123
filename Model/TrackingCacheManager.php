<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Pynarae\Tracking\Model\ResourceModel\Cache as CacheResource;
use Pynarae\Tracking\Model\ResourceModel\Cache\CollectionFactory as CacheCollectionFactory;

class TrackingCacheManager
{
    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly CacheResource $cacheResource,
        private readonly CacheCollectionFactory $cacheCollectionFactory,
        private readonly StatusNormalizer $statusNormalizer,
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

    public function getByTrackingNumber(string $trackingNumber): ?Cache
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return null;
        }

        $collection = $this->cacheCollectionFactory->create();
        $collection->addFieldToFilter('tracking_number', $trackingNumber);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }

    public function isStale(Cache $cache, int $minutes): bool
    {
        $lastSyncedAt = (string) $cache->getData('last_synced_at');
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
        $trackId = (int) ($context['track_id'] ?? 0);
        $trackingNumber = (string) ($trackData['trackingNumber'] ?? $trackData['trackNo'] ?? $context['tracking_number'] ?? '');
        $cache = $trackId > 0 ? $this->getByTrackId($trackId) : null;
        if (!$cache && $trackingNumber !== '') {
            $cache = $this->getByTrackingNumber($trackingNumber);
        }
        if (!$cache) {
            $cache = $this->cacheFactory->create();
            if ($trackId > 0) {
                $cache->setData('track_id', $trackId);
            }
        } elseif ($trackId > 0 && !$cache->getData('track_id')) {
            $cache->setData('track_id', $trackId);
        }

        $carrierInfo = is_array($trackData['carrierInfo'] ?? null) ? $trackData['carrierInfo'] : [];
        $localLogisticsInfo = is_array($trackData['localLogisticsInfo'] ?? null) ? $trackData['localLogisticsInfo'] : [];
        $events = $this->extractEvents($trackData);
        $status = $this->statusNormalizer->normalize(
            (string) ($trackData['transitStatus'] ?? $trackData['deliveryStatus'] ?? ''),
            (string) ($trackData['transitSubStatus'] ?? $trackData['deliverySubStatus'] ?? '')
        );

        $cache->addData([
            'store_id' => $context['store_id'] ?? $cache->getData('store_id'),
            'order_id' => $context['order_id'] ?? $cache->getData('order_id'),
            'order_increment_id' => $context['order_increment_id'] ?? $cache->getData('order_increment_id'),
            'shipment_id' => $context['shipment_id'] ?? $cache->getData('shipment_id'),
            'tracking_number' => $trackData['trackingNumber'] ?? $trackData['trackNo'] ?? $context['tracking_number'] ?? $cache->getData('tracking_number'),
            'carrier_code' => $carrierInfo['code'] ?? $trackData['courierCode'] ?? $localLogisticsInfo['courierCode'] ?? $cache->getData('carrier_code'),
            'carrier_name' => $carrierInfo['name'] ?? $localLogisticsInfo['courierNameEN'] ?? $localLogisticsInfo['courierNameCN'] ?? $cache->getData('carrier_name'),
            'normalized_status' => $status['normalized_status'],
            'transit_status' => $trackData['transitStatus'] ?? $trackData['deliveryStatus'] ?? null,
            'transit_sub_status' => $trackData['transitSubStatus'] ?? $trackData['deliverySubStatus'] ?? null,
            'signed_by' => $trackData['signedBy'] ?? null,
            'external_query_url' => $this->buildCarrierQueryUrl($carrierInfo, $localLogisticsInfo, (string) ($trackData['trackingNumber'] ?? $trackData['trackNo'] ?? '')),
            'carrier_homepage' => $carrierInfo['homePage'] ?? $localLogisticsInfo['courierHomePage'] ?? null,
            'carrier_logo' => $carrierInfo['logo'] ?? null,
            'transit_days' => $trackData['transitDays'] ?? null,
            'stay_days' => $trackData['stayDays'] ?? null,
            'last_tracking_time' => $this->normalizeDate($trackData['lastTrackingTime'] ?? null),
            'delivery_date' => $this->normalizeDate($trackData['deliveryDate'] ?? null),
            'is_registered' => 1,
            'last_synced_at' => $this->dateTime->gmtDate(),
            'events_json' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_response_json' => json_encode($trackData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    /**
     * @param array<string, mixed> $carrierInfo
     */
    private function buildCarrierQueryUrl(array $carrierInfo, array $localLogisticsInfo, string $trackingNumber): ?string
    {
        $queryLink = (string)($carrierInfo['queryLink'] ?? $localLogisticsInfo['courierTrackingLink'] ?? '');
        if ($queryLink === '') {
            return null;
        }

        return str_replace('{trackingNo}', $trackingNumber, $queryLink);
    }

    /**
     * @param mixed $value
     */
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

    /**
     * @param array<string, mixed> $trackData
     * @return array<int, array<string, string|null>>
     */
    private function extractEvents(array $trackData): array
    {
        $events = [];
        $localLogisticsInfo = is_array($trackData['localLogisticsInfo'] ?? null) ? $trackData['localLogisticsInfo'] : [];
        $trackInfo = $trackData['trackInfo']
            ?? $trackData['checkpoints']
            ?? $trackData['events']
            ?? ($localLogisticsInfo['trackingDetails'] ?? []);
        if (!is_array($trackInfo)) {
            return $events;
        }

        foreach ($trackInfo as $item) {
            if (!is_array($item)) {
                continue;
            }
            $events[] = [
                'time' => (string) ($item['eventTime'] ?? $item['time'] ?? ''),
                'detail' => (string) ($item['eventDetail'] ?? $item['checkpointStatus'] ?? $item['description'] ?? ''),
                'location' => (string) ($item['eventLocation'] ?? $item['location'] ?? $item['address'] ?? ''),
            ];
        }

        return $events;
    }
}
