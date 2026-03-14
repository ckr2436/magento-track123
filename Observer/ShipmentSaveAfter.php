<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\Job;
use Pynarae\Tracking\Model\QueueManager;

class ShipmentSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueManager $queueManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment instanceof Shipment || !(int)$shipment->getId()) {
            return;
        }

        $order = $shipment->getOrder();
        $storeId = (int)$order->getStoreId();
        if (!$this->config->shouldAutoRegisterOnTrackSave($storeId)) {
            return;
        }

        /** @var Track $track */
        foreach ($shipment->getTracksCollection() as $track) {
            $trackId = (int)$track->getId();
            $trackingNumber = trim((string)$track->getTrackNumber());
            if ($trackId <= 0 || $trackingNumber === '') {
                continue;
            }

            try {
                $this->queueManager->enqueue(Job::TYPE_REGISTER, [
                    'track_id' => $trackId,
                    'tracking_number' => $trackingNumber,
                    'shipment_id' => (int)$shipment->getId(),
                    'order_id' => (int)$order->getId(),
                ], $storeId);
            } catch (\Throwable $exception) {
                $this->logger->error('Failed to enqueue tracking registration job from shipment save', [
                    'shipment_id' => $shipment->getId(),
                    'track_id' => $trackId,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
