<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Ship24;

use Pynarae\Tracking\Model\Dto\ProviderEvent;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\StatusNormalizer;

class Ship24PayloadMapper
{
    public function __construct(private readonly StatusNormalizer $statusNormalizer)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function map(array $payload): ProviderTrackingResult
    {
        $tracker = is_array($payload['tracker'] ?? null) ? $payload['tracker'] : [];
        $shipment = is_array($payload['shipment'] ?? null) ? $payload['shipment'] : [];
        $statusCode = (string)($shipment['status_code'] ?? $shipment['status'] ?? $tracker['status'] ?? '');
        $statusMilestone = (string)($shipment['status_milestone'] ?? $shipment['sub_status'] ?? '');
        $normalized = $this->statusNormalizer->normalize($statusCode, $statusMilestone);

        $trackingNumber = (string)($tracker['tracking_number'] ?? $payload['tracking_number'] ?? '');
        $events = $this->extractEvents($payload, $trackingNumber);

        return new ProviderTrackingResult(
            providerCode: 'ship24',
            trackingNumber: $trackingNumber,
            externalTrackerId: isset($tracker['tracker_id']) ? (string)$tracker['tracker_id'] : null,
            externalShipmentId: isset($shipment['shipment_id']) ? (string)$shipment['shipment_id'] : null,
            carrierCode: isset($shipment['courier_code']) ? (string)$shipment['courier_code'] : null,
            carrierName: isset($shipment['courier_name']) ? (string)$shipment['courier_name'] : null,
            normalizedStatus: (string)$normalized['normalized_status'],
            providerStatusCode: $statusCode !== '' ? $statusCode : null,
            providerStatusCategory: isset($shipment['status_category']) ? (string)$shipment['status_category'] : null,
            providerStatusMilestone: $statusMilestone !== '' ? $statusMilestone : null,
            statusLabel: (string)$normalized['status_label'],
            subStatusLabel: $normalized['sub_status_label'] !== '' ? (string)$normalized['sub_status_label'] : null,
            signedBy: isset($shipment['signed_by']) ? (string)$shipment['signed_by'] : null,
            externalQueryUrl: isset($tracker['tracking_url']) ? (string)$tracker['tracking_url'] : null,
            lastTrackingTime: $this->normalizeDate($shipment['last_updated'] ?? $shipment['updated_at'] ?? null),
            deliveryDate: $this->normalizeDate($shipment['delivered_at'] ?? null),
            events: $events,
            rawPayload: $payload,
            meta: [
                'statistics' => is_array($payload['statistics'] ?? null) ? $payload['statistics'] : [],
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ]
        );
    }

    /**
     * @param array<string,mixed> $response
     * @return ProviderTrackingResult[]
     */
    public function mapResponseItems(array $response): array
    {
        $rows = $response['data'] ?? $response['trackers'] ?? [];
        if ($this->looksLikeItem($response)) {
            return [$this->map($response)];
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (is_array($row) && $this->looksLikeItem($row)) {
                $items[] = $this->map($row);
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $payload
     * @return ProviderEvent[]
     */
    private function extractEvents(array $payload, string $trackingNumber): array
    {
        $events = $payload['events'] ?? $payload['shipment']['events'] ?? [];
        if (!is_array($events)) {
            return [];
        }

        $result = [];
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                continue;
            }
            $result[] = new ProviderEvent(
                eventId: isset($event['event_id']) ? (string)$event['event_id'] : null,
                trackingNumber: $trackingNumber !== '' ? $trackingNumber : null,
                status: isset($event['description']) ? (string)$event['description'] : null,
                statusCode: isset($event['status_code']) ? (string)$event['status_code'] : null,
                statusCategory: isset($event['status_category']) ? (string)$event['status_category'] : null,
                statusMilestone: isset($event['status_milestone']) ? (string)$event['status_milestone'] : null,
                time: $this->normalizeDate($event['occurred_at'] ?? $event['event_time'] ?? null),
                location: isset($event['location']) ? (string)$event['location'] : null,
                courierCode: isset($event['courier_code']) ? (string)$event['courier_code'] : null,
                order: is_int($index) ? $index : null,
                raw: $event
            );
        }

        return $result;
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
     * @param array<string,mixed> $value
     */
    private function looksLikeItem(array $value): bool
    {
        return isset($value['tracker']) || isset($value['shipment']) || isset($value['events']);
    }
}
