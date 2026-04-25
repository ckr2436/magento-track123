<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Kd100;

use Pynarae\Tracking\Model\Dto\ProviderEvent;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\StatusNormalizer;

class Kd100PayloadMapper
{
    public function __construct(private readonly StatusNormalizer $statusNormalizer)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function map(array $payload): ProviderTrackingResult
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $trackingNumber = trim((string)($data['tracking_number'] ?? $data['trackingNumber'] ?? $payload['tracking_number'] ?? ''));
        $carrierCode = trim((string)($data['carrier_id'] ?? $data['carrierId'] ?? $payload['carrier_id'] ?? ''));
        $carrierName = trim((string)($data['carrier_name'] ?? $data['carrierName'] ?? $payload['carrier_name'] ?? ''));

        $providerStatusCode = trim((string)($data['order_status_code'] ?? $data['status_code'] ?? $data['statusCode'] ?? ''));
        $providerStatusDescription = trim((string)($data['order_status_description'] ?? $data['status_description'] ?? $data['status'] ?? ''));
        $statusSeed = $this->mapKd100StatusToSeed($providerStatusCode, $providerStatusDescription);
        $normalized = $this->statusNormalizer->normalize($statusSeed, $providerStatusDescription !== '' ? $providerStatusDescription : $providerStatusCode);

        $events = $this->extractEvents($data, $trackingNumber, $carrierCode);
        $lastTrackingTime = $this->extractLastTrackingTime($events, $data);

        return new ProviderTrackingResult(
            providerCode: 'kd100',
            trackingNumber: $trackingNumber,
            externalTrackerId: isset($data['id']) ? (string)$data['id'] : null,
            externalShipmentId: null,
            carrierCode: $carrierCode !== '' ? $carrierCode : null,
            carrierName: $carrierName !== '' ? $carrierName : null,
            normalizedStatus: (string)$normalized['normalized_status'],
            providerStatusCode: $providerStatusCode !== '' ? $providerStatusCode : null,
            providerStatusCategory: $providerStatusDescription !== '' ? $providerStatusDescription : null,
            providerStatusMilestone: null,
            statusLabel: (string)$normalized['status_label'],
            subStatusLabel: $normalized['sub_status_label'] !== '' ? (string)$normalized['sub_status_label'] : null,
            signedBy: isset($data['signed_by']) ? (string)$data['signed_by'] : null,
            externalQueryUrl: isset($data['tracking_url']) ? (string)$data['tracking_url'] : null,
            lastTrackingTime: $lastTrackingTime,
            deliveryDate: $this->extractDeliveryDate($events, $data),
            events: $events,
            rawPayload: $payload,
            meta: [
                'kd100_data' => $data,
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
        $data = $response['data'] ?? null;

        if (is_array($data) && $this->looksLikeTrackingItem($data)) {
            return [$this->map(['data' => $data])];
        }

        if (is_array($data) && array_is_list($data)) {
            foreach ($data as $row) {
                if (is_array($row) && $this->looksLikeTrackingItem($row)) {
                    $items[] = $this->map(['data' => $row]);
                }
            }
        }

        if (isset($response['trackings']) && is_array($response['trackings'])) {
            foreach ($response['trackings'] as $row) {
                if (is_array($row) && $this->looksLikeTrackingItem($row)) {
                    $items[] = $this->map($row);
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $data
     * @return ProviderEvent[]
     */
    private function extractEvents(array $data, string $fallbackTrackingNumber, string $fallbackCarrierCode): array
    {
        $rawEvents = [];
        foreach (['items', 'events', 'origin_info', 'destination_info'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $rawEvents = $data[$key];
                break;
            }
        }

        if ($rawEvents === []) {
            return [];
        }

        $events = [];
        foreach ($rawEvents as $index => $event) {
            if (!is_array($event)) {
                continue;
            }

            $time = $this->normalizeDate($event['time'] ?? $event['datetime'] ?? $event['date'] ?? null);
            $statusCode = trim((string)($event['order_status_code'] ?? $event['status_code'] ?? $event['statusCode'] ?? ''));
            $status = trim((string)($event['order_status_description'] ?? $event['status'] ?? $event['context'] ?? $event['description'] ?? ''));
            $location = trim((string)($event['location'] ?? $event['area_name'] ?? $event['areaName'] ?? ''));
            $courierCode = trim((string)($event['carrier_id'] ?? $event['carrierId'] ?? $fallbackCarrierCode));

            $events[] = new ProviderEvent(
                eventId: $time !== null ? hash('sha256', $fallbackTrackingNumber . '|' . $time . '|' . $status . '|' . $location) : null,
                trackingNumber: $fallbackTrackingNumber !== '' ? $fallbackTrackingNumber : null,
                status: $status !== '' ? $status : null,
                statusCode: $statusCode !== '' ? $statusCode : null,
                statusCategory: null,
                statusMilestone: null,
                time: $time,
                location: $location !== '' ? $location : null,
                courierCode: $courierCode !== '' ? $courierCode : null,
                order: is_int($index) ? $index : null,
                raw: $event
            );
        }

        return $events;
    }

    /**
     * @param ProviderEvent[] $events
     * @param array<string,mixed> $data
     */
    private function extractLastTrackingTime(array $events, array $data): ?string
    {
        foreach ($events as $event) {
            if ($event->time !== null && $event->time !== '') {
                return $event->time;
            }
        }

        return $this->normalizeDate($data['last_update_time'] ?? $data['updated_at'] ?? null);
    }

    /**
     * @param ProviderEvent[] $events
     * @param array<string,mixed> $data
     */
    private function extractDeliveryDate(array $events, array $data): ?string
    {
        $direct = $this->normalizeDate($data['delivery_date'] ?? $data['delivered_at'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($events as $event) {
            $haystack = mb_strtolower(trim(($event->status ?? '') . ' ' . ($event->statusCode ?? '')));
            if ($event->time !== null && str_contains($haystack, 'deliver')) {
                return $event->time;
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

    private function mapKd100StatusToSeed(string $code, string $description): string
    {
        $description = mb_strtolower(trim($description));
        return match ($code) {
            '0', '1', '7', '8' => 'in_transit',
            '3' => 'delivered',
            '5' => 'out_for_delivery',
            '2', '4', '6' => 'exception',
            default => $description !== '' ? $description : 'unknown',
        };
    }

    /**
     * @param array<string,mixed> $value
     */
    private function looksLikeTrackingItem(array $value): bool
    {
        return isset($value['tracking_number'])
            || isset($value['trackingNumber'])
            || isset($value['carrier_id'])
            || isset($value['items'])
            || isset($value['events'])
            || isset($value['order_status_code']);
    }
}
