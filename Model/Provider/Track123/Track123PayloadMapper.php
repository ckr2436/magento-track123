<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Pynarae\Tracking\Model\Dto\ProviderEvent;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\StatusNormalizer;
use Pynarae\Tracking\Model\Track123PayloadExtractor;

class Track123PayloadMapper
{
    public function __construct(
        private readonly StatusNormalizer $statusNormalizer,
        private readonly Track123PayloadExtractor $payloadExtractor
    ) {
    }

    /**
     * @param array<string,mixed> $trackData
     */
    public function map(array $trackData): ProviderTrackingResult
    {
        $carrierInfo = is_array($trackData['carrierInfo'] ?? null) ? $trackData['carrierInfo'] : [];
        $localLogisticsInfo = is_array($trackData['localLogisticsInfo'] ?? null) ? $trackData['localLogisticsInfo'] : [];
        $transitStatus = (string)($trackData['transitStatus'] ?? $trackData['deliveryStatus'] ?? '');
        $transitSubStatus = (string)($trackData['transitSubStatus'] ?? $trackData['deliverySubStatus'] ?? '');
        $status = $this->statusNormalizer->normalize($transitStatus, $transitSubStatus);
        $trackingNumber = (string)($trackData['trackingNumber'] ?? $trackData['trackNo'] ?? '');

        return new ProviderTrackingResult(
            providerCode: 'track123',
            trackingNumber: $trackingNumber,
            externalTrackerId: isset($trackData['id']) ? (string)$trackData['id'] : null,
            externalShipmentId: null,
            carrierCode: $carrierInfo['code'] ?? $trackData['courierCode'] ?? $localLogisticsInfo['courierCode'] ?? null,
            carrierName: $carrierInfo['name'] ?? $localLogisticsInfo['courierNameEN'] ?? $localLogisticsInfo['courierNameCN'] ?? null,
            normalizedStatus: (string)$status['normalized_status'],
            providerStatusCode: $transitStatus !== '' ? $transitStatus : null,
            providerStatusCategory: null,
            providerStatusMilestone: $transitSubStatus !== '' ? $transitSubStatus : null,
            statusLabel: (string)$status['status_label'],
            subStatusLabel: $status['sub_status_label'] !== '' ? (string)$status['sub_status_label'] : null,
            signedBy: isset($trackData['signedBy']) ? (string)$trackData['signedBy'] : null,
            externalQueryUrl: $this->buildCarrierQueryUrl($carrierInfo, $localLogisticsInfo, $trackingNumber),
            lastTrackingTime: $this->normalizeDate($trackData['lastTrackingTime'] ?? null),
            deliveryDate: $this->normalizeDate($trackData['deliveryDate'] ?? null),
            events: $this->extractEvents($trackData),
            rawPayload: $trackData,
            meta: [
                'carrier_homepage' => $carrierInfo['homePage'] ?? $localLogisticsInfo['courierHomePage'] ?? null,
                'carrier_logo' => $carrierInfo['logo'] ?? null,
                'transit_days' => $trackData['transitDays'] ?? null,
                'stay_days' => $trackData['stayDays'] ?? null,
            ]
        );
    }

    /**
     * @param array<string,mixed> $response
     * @return ProviderTrackingResult[]
     */
    public function mapResponseItems(array $response): array
    {
        $items = [];

        foreach ($this->payloadExtractor->extractTrackingItems($response) as $row) {
            if (is_array($row)) {
                $items[] = $this->map($row);
            }
        }

        if ($items !== []) {
            return $items;
        }

        if ($this->looksLikeTrack($response)) {
            return [$this->map($response)];
        }

        return [];
    }

    private function looksLikeTrack(array $value): bool
    {
        return isset($value['trackingNumber']) || isset($value['trackNo']) || isset($value['transitStatus']);
    }

    private function buildCarrierQueryUrl(array $carrierInfo, array $localLogisticsInfo, string $trackingNumber): ?string
    {
        $queryLink = (string)($carrierInfo['queryLink'] ?? $localLogisticsInfo['courierTrackingLink'] ?? '');
        if ($queryLink === '') {
            return null;
        }

        return str_replace('{trackingNo}', $trackingNumber, $queryLink);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string,mixed> $trackData
     * @return ProviderEvent[]
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
            $events[] = new ProviderEvent(
                eventId: isset($item['id']) ? (string)$item['id'] : null,
                trackingNumber: isset($trackData['trackingNumber']) ? (string)$trackData['trackingNumber'] : null,
                status: isset($item['eventDetail']) ? (string)$item['eventDetail'] : null,
                statusCode: isset($item['checkpointStatus']) ? (string)$item['checkpointStatus'] : null,
                statusCategory: null,
                statusMilestone: null,
                time: (string)($item['eventTime'] ?? $item['time'] ?? ''),
                location: (string)($item['eventLocation'] ?? $item['location'] ?? $item['address'] ?? ''),
                courierCode: isset($trackData['courierCode']) ? (string)$trackData['courierCode'] : null,
                order: isset($item['order']) ? (int)$item['order'] : null,
                raw: $item
            );
        }

        return $events;
    }
}
