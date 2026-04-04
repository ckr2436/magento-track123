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
        $delivery = is_array($shipment['delivery'] ?? null) ? $shipment['delivery'] : [];
        $statistics = is_array($payload['statistics'] ?? null) ? $payload['statistics'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $statusCode = trim((string)($shipment['statusCode'] ?? ''));
        $statusCategory = trim((string)($shipment['statusCategory'] ?? ''));
        $statusMilestone = trim((string)($shipment['statusMilestone'] ?? ''));

        $normalized = $this->statusNormalizer->normalize(
            $statusMilestone !== '' ? $statusMilestone : $statusCode,
            $statusCode !== '' ? $statusCode : $statusCategory
        );

        $trackingNumber = trim((string)($tracker['trackingNumber'] ?? $payload['trackingNumber'] ?? ''));
        $events = $this->extractEvents($payload, $trackingNumber);

        $carrierCode = $this->extractCarrierCode($tracker);
        if ($carrierCode === null && $events !== []) {
            foreach ($events as $event) {
                if ($event->courierCode !== null && $event->courierCode !== '') {
                    $carrierCode = $event->courierCode;
                    break;
                }
            }
        }

        return new ProviderTrackingResult(
            providerCode: 'ship24',
            trackingNumber: $trackingNumber,
            externalTrackerId: isset($tracker['trackerId']) ? (string)$tracker['trackerId'] : null,
            externalShipmentId: isset($shipment['shipmentId']) ? (string)$shipment['shipmentId'] : null,
            carrierCode: $carrierCode,
            carrierName: isset($delivery['service']) ? (string)$delivery['service'] : null,
            normalizedStatus: (string)$normalized['normalized_status'],
            providerStatusCode: $statusCode !== '' ? $statusCode : null,
            providerStatusCategory: $statusCategory !== '' ? $statusCategory : null,
            providerStatusMilestone: $statusMilestone !== '' ? $statusMilestone : null,
            statusLabel: (string)$normalized['status_label'],
            subStatusLabel: $normalized['sub_status_label'] !== '' ? (string)$normalized['sub_status_label'] : null,
            signedBy: isset($delivery['signedBy']) ? (string)$delivery['signedBy'] : null,
            externalQueryUrl: null,
            lastTrackingTime: $this->extractLastTrackingTime($events, $metadata),
            deliveryDate: $this->normalizeDate(
                $delivery['estimatedDeliveryDate']
                    ?? ($statistics['timestamps']['deliveredDatetime'] ?? null)
            ),
            events: $events,
            rawPayload: $payload,
            meta: [
                'statistics' => $statistics,
                'metadata' => $metadata,
                'recipient' => is_array($shipment['recipient'] ?? null) ? $shipment['recipient'] : [],
                'shipment' => $shipment,
            ]
        );
    }

    /**
     * @param array<string,mixed> $response
     * @return ProviderTrackingResult[]
     */
    public function mapResponseItems(array $response): array
    {
        if ($this->looksLikeTrackingItem($response)) {
            return [$this->map($response)];
        }

        $items = [];

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        if (isset($data['trackings']) && is_array($data['trackings'])) {
            foreach ($data['trackings'] as $row) {
                if (is_array($row) && $this->looksLikeTrackingItem($row)) {
                    $items[] = $this->map($row);
                }
            }
            return $items;
        }

        if (isset($response['trackings']) && is_array($response['trackings'])) {
            foreach ($response['trackings'] as $row) {
                if (is_array($row) && $this->looksLikeTrackingItem($row)) {
                    $items[] = $this->map($row);
                }
            }
            return $items;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return ProviderEvent[]
     */
    private function extractEvents(array $payload, string $fallbackTrackingNumber): array
    {
        $events = $payload['events'] ?? [];
        if (!is_array($events)) {
            return [];
        }

        $result = [];
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                continue;
            }

            $result[] = new ProviderEvent(
                eventId: isset($event['eventId']) ? (string)$event['eventId'] : null,
                trackingNumber: isset($event['eventTrackingNumber'])
                    ? (string)$event['eventTrackingNumber']
                    : (isset($event['trackingNumber']) ? (string)$event['trackingNumber'] : ($fallbackTrackingNumber !== '' ? $fallbackTrackingNumber : null)),
                status: isset($event['status']) ? (string)$event['status'] : null,
                statusCode: isset($event['statusCode']) ? (string)$event['statusCode'] : null,
                statusCategory: isset($event['statusCategory']) ? (string)$event['statusCategory'] : null,
                statusMilestone: isset($event['statusMilestone']) ? (string)$event['statusMilestone'] : null,
                time: $this->normalizeDate($event['occurrenceDatetime'] ?? ($event['datetime'] ?? null)),
                location: isset($event['location']) ? (string)$event['location'] : null,
                courierCode: isset($event['courierCode']) ? (string)$event['courierCode'] : null,
                order: isset($event['order']) && is_numeric($event['order']) ? (int)$event['order'] : (is_int($index) ? $index : null),
                raw: $event
            );
        }

        return $result;
    }

    /**
     * @param ProviderEvent[] $events
     * @param array<string,mixed> $metadata
     */
    private function extractLastTrackingTime(array $events, array $metadata): ?string
    {
        foreach ($events as $event) {
            if ($event->time !== null && $event->time !== '') {
                return $event->time;
            }
        }

        return $this->normalizeDate($metadata['generatedAt'] ?? null);
    }

    /**
     * tracker.courierCode can be string or array
     *
     * @param array<string,mixed> $tracker
     */
    private function extractCarrierCode(array $tracker): ?string
    {
        $value = $tracker['courierCode'] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value)) {
            foreach ($value as $row) {
                if (is_string($row) && trim($row) !== '') {
                    return trim($row);
                }
            }
        }

        return null;
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

    /**
     * @param array<string,mixed> $value
     */
    private function looksLikeTrackingItem(array $value): bool
    {
        return isset($value['tracker']) || isset($value['shipment']) || isset($value['events']) || isset($value['metadata']);
    }
}
