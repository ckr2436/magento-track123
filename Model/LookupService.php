<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;

class LookupService
{
    public function __construct(
        private readonly GuestOrderLocator $guestOrderLocator,
        private readonly StoreTrackingLocator $storeTrackingLocator,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly TrackingSynchronizer $trackingSynchronizer,
        private readonly Config $config,
        private readonly StatusNormalizer $statusNormalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function lookupByOrder(string $incrementId, string $emailOrPhone, array $manualVerification = []): array
    {
        $order = $this->guestOrderLocator->locate($incrementId, $emailOrPhone);
        $tracks = $this->collectTracks($order);

        if ($tracks === []) {
            throw new LocalizedException(__('This order exists, but no shipment tracking numbers are available yet.'));
        }

        $results = [];
        foreach ($tracks as $track) {
            $results[] = $this->buildTrackResult($track, $order, 'order', $manualVerification);
        }

        return [
            'lookup_mode' => 'order',
            'headline' => (string)__('Order Tracking'),
            'order_increment_id' => $order->getIncrementId(),
            'order_status' => $order->getStatus(),
            'order_status_label' => $order->getStatusLabel(),
            'order_date' => $order->getCreatedAt(),
            'tracks' => $results,
        ];
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function lookupByTrackingNumber(string $trackingNumber, array $manualVerification = []): array
    {
        $tracks = $this->storeTrackingLocator->locate($trackingNumber);
        $results = [];

        foreach ($tracks as $track) {
            $results[] = $this->buildTrackResult($track, null, 'tracking', $manualVerification);
        }

        return [
            'lookup_mode' => 'tracking',
            'headline' => (string)__('Tracking Details'),
            'tracking_number' => $trackingNumber,
            'tracks' => $results,
        ];
    }

    /**
     * @return array<int, Track>
     */
    private function collectTracks(Order $order): array
    {
        $tracks = [];

        /** @var Shipment $shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                if ((string)$track->getTrackNumber() !== '') {
                    $tracks[] = $track;
                }
            }
        }

        usort(
            $tracks,
            static fn(Track $a, Track $b): int => (int)$b->getId() <=> (int)$a->getId()
        );

        return $tracks;
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function buildTrackResult(Track $track, ?Order $order, string $lookupMode, array $manualVerification = []): array
    {
        $shipment = $track->getShipment();
        $resolvedOrder = $order ?: $shipment->getOrder();
        $storeId = (int)$resolvedOrder->getStoreId();
        $cache = $this->trackingCacheManager->getByTrackId((int)$track->getId());
        $warning = null;

        $allowFrontendRefresh = $this->config->useLiveRefreshOnLookup($storeId)
            && !$this->config->isWebhookEnabled($storeId);

        $shouldRefresh = $allowFrontendRefresh
            && ($cache === null || $this->trackingCacheManager->isStale($cache, $this->config->getQueryStaleAfterMinutes($storeId)));

        if ($shouldRefresh) {
            try {
                if (!$cache || !(bool)$cache->getData('is_registered')) {
                    $this->trackingSynchronizer->registerTrack($track, $manualVerification);
                }

                $this->trackingSynchronizer->queryTrack($track, $manualVerification);
                $cache = $this->trackingCacheManager->getByTrackId((int)$track->getId());
            } catch (AdditionalVerificationRequiredException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $warning = (string)__('Live tracking refresh is temporarily unavailable. Showing the latest cached data if available.');
                $this->logger->warning('Tracking lookup live refresh failed', [
                    'track_id' => $track->getId(),
                    'order_id' => $resolvedOrder->getId(),
                    'lookup_mode' => $lookupMode,
                    'exception' => $e,
                ]);
            }
        }

        $events = [];
        $normalizedStatus = 'pending';
        $statusLabel = 'Pending';
        $subStatusLabel = null;
        $externalTrackingUrl = null;
        $carrierName = (string)($track->getTitle() ?: $track->getCarrierCode() ?: 'Carrier');
        $carrierLogo = null;
        $lastTrackingTime = null;
        $deliveryDate = null;
        $signedBy = null;
        $transitDays = null;
        $stayDays = null;
        $isStale = false;

        if ($cache) {
            $events = json_decode((string)$cache->getData('events_json'), true) ?: [];
            $normalizedStatus = (string)($cache->getData('normalized_status') ?: 'pending');
            $transitStatus = (string)$cache->getData('transit_status');
            $transitSubStatus = (string)$cache->getData('transit_sub_status');
            $normalized = $this->statusNormalizer->normalize($transitStatus, $transitSubStatus);
            $statusLabel = $normalized['status_label'];
            $subStatusLabel = $normalized['sub_status_label'];
            $externalTrackingUrl = $cache->getData('external_query_url');
            $carrierName = (string)($cache->getData('carrier_name') ?: $carrierName);
            $carrierLogo = $cache->getData('carrier_logo');
            $lastTrackingTime = $cache->getData('last_tracking_time');
            $deliveryDate = $cache->getData('delivery_date');
            $signedBy = $cache->getData('signed_by');
            $transitDays = $cache->getData('transit_days');
            $stayDays = $cache->getData('stay_days');
            $isStale = $this->trackingCacheManager->isStale($cache, $this->config->getCacheTtlMinutes($storeId));
        }

        return [
            'lookup_mode' => $lookupMode,
            'track_id' => (int)$track->getId(),
            'tracking_number' => (string)$track->getTrackNumber(),
            'carrier_name' => $carrierName,
            'carrier_code' => (string)$track->getCarrierCode(),
            'normalized_status' => $normalizedStatus,
            'status_label' => $statusLabel,
            'sub_status_label' => $subStatusLabel,
            'events' => array_slice($events, 0, $this->config->getMaxEventsDisplay($storeId)),
            'external_query_url' => $externalTrackingUrl,
            'carrier_logo' => $carrierLogo,
            'last_tracking_time' => $lastTrackingTime,
            'delivery_date' => $deliveryDate,
            'signed_by' => $signedBy,
            'transit_days' => $transitDays,
            'stay_days' => $stayDays,
            'warning' => $warning,
            'is_stale' => $isStale,
        ];
    }
}
