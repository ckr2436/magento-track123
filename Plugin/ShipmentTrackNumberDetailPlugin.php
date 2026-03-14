<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Plugin;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\StatusNormalizer;
use Pynarae\Tracking\Model\TrackingCacheManager;
use Pynarae\Tracking\Model\TrackingSynchronizer;

class ShipmentTrackNumberDetailPlugin
{
    public function __construct(
        private readonly TrackingSynchronizer $trackingSynchronizer,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly StatusNormalizer $statusNormalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundGetNumberDetail(Track $subject, callable $proceed): mixed
    {
        $result = $proceed();
        if (!$this->shouldApplyFallback($result) || !(int)$subject->getId()) {
            return $result;
        }

        try {
            $cache = $this->trackingCacheManager->getByTrackId((int)$subject->getId());
            if ($cache === null || !(bool)$cache->getData('is_registered')) {
                $this->trackingSynchronizer->registerTrack($subject);
            }

            $this->trackingSynchronizer->queryTrack($subject);
            $cache = $this->trackingCacheManager->getByTrackId((int)$subject->getId());
            if ($cache === null) {
                return $result;
            }

            $carrierTitle = (string)($cache->getData('carrier_name') ?: $subject->getTitle() ?: __('Carrier'));
            $transitStatus = (string)$cache->getData('transit_status');
            $transitSubStatus = (string)$cache->getData('transit_sub_status');
            $normalized = $this->statusNormalizer->normalize($transitStatus, $transitSubStatus);

            return new DataObject([
                'carrier' => (string)$subject->getCarrierCode(),
                'carrier_title' => $carrierTitle,
                'title' => $carrierTitle,
                'number' => (string)$subject->getTrackNumber(),
                'tracking' => (string)$subject->getTrackNumber(),
                'status' => (string)$normalized['status_label'],
                'track_summary' => (string)($normalized['sub_status_label'] ?: $transitSubStatus),
                'url' => (string)$cache->getData('external_query_url'),
                'progressdetail' => $this->buildProgressDetail((string)$cache->getData('events_json')),
                'error_message' => null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Popup tracking fallback failed', [
                'track_id' => (int)$subject->getId(),
                'tracking_number' => (string)$subject->getTrackNumber(),
                'exception' => $exception,
            ]);

            return $result;
        }
    }

    private function shouldApplyFallback(mixed $result): bool
    {
        if ($result === null || $result === false) {
            return true;
        }

        if (is_array($result)) {
            return trim((string)($result['error_message'] ?? '')) !== '';
        }

        if ($result instanceof DataObject) {
            return trim((string)$result->getData('error_message')) !== '';
        }

        return false;
    }

    /**
     * @return array<int, array{deliverydate:string,deliverytime:string,deliverylocation:string,activity:string}>
     */
    private function buildProgressDetail(string $eventsJson): array
    {
        if ($eventsJson === '') {
            return [];
        }

        $events = json_decode($eventsJson, true);
        if (!is_array($events)) {
            return [];
        }

        $progress = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $time = (string)($event['time'] ?? '');
            $progress[] = [
                'deliverydate' => $this->extractDatePart($time),
                'deliverytime' => $this->extractTimePart($time),
                'deliverylocation' => (string)($event['location'] ?? ''),
                'activity' => (string)($event['detail'] ?? ''),
            ];
        }

        return $progress;
    }

    private function extractDatePart(string $dateTime): string
    {
        if ($dateTime === '') {
            return '';
        }

        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }

    private function extractTimePart(string $dateTime): string
    {
        if ($dateTime === '') {
            return '';
        }

        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('H:i', $timestamp);
    }
}

