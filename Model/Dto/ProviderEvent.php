<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Dto;

class ProviderEvent
{
    public function __construct(
        public readonly ?string $eventId,
        public readonly ?string $trackingNumber,
        public readonly ?string $status,
        public readonly ?string $statusCode,
        public readonly ?string $statusCategory,
        public readonly ?string $statusMilestone,
        public readonly ?string $time,
        public readonly ?string $location,
        public readonly ?string $courierCode,
        public readonly ?int $order = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'tracking_number' => $this->trackingNumber,
            'status' => $this->status,
            'status_code' => $this->statusCode,
            'status_category' => $this->statusCategory,
            'status_milestone' => $this->statusMilestone,
            'time' => $this->time,
            'location' => $this->location,
            'courier_code' => $this->courierCode,
            'order' => $this->order,
            'raw' => $this->raw,
        ];
    }
}
