<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

class StatusNormalizer
{
    /**
     * @return array{normalized_status:string,status_label:string,sub_status_label:?string}
     */
    public function normalize(?string $transitStatus, ?string $transitSubStatus): array
    {
        $status = strtolower(trim((string) $transitStatus));
        $subStatus = trim((string) $transitSubStatus);

        $map = [
            'pending' => 'pending',
            'notfound' => 'pending',
            'info_received' => 'info_received',
            'info received' => 'info_received',
            'in_transit' => 'in_transit',
            'in transit' => 'in_transit',
            'pickup' => 'in_transit',
            'out_for_delivery' => 'out_for_delivery',
            'out for delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            'exception' => 'exception',
            'failed_attempt' => 'exception',
            'failed attempt' => 'exception',
            'expired' => 'exception',
            'available_for_pickup' => 'out_for_delivery',
            'available for pickup' => 'out_for_delivery',
        ];

        $normalized = $map[$status] ?? 'unknown';
        $labels = [
            'pending' => 'Pending',
            'info_received' => 'Info Received',
            'in_transit' => 'In Transit',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'exception' => 'Exception',
            'unknown' => 'Unknown',
        ];

        return [
            'normalized_status' => $normalized,
            'status_label' => $labels[$normalized] ?? 'Unknown',
            'sub_status_label' => $subStatus !== '' ? $subStatus : null,
        ];
    }
}
