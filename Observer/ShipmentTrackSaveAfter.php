<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\Job;
use Pynarae\Tracking\Model\QueueManager;

class ShipmentTrackSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueManager $queueManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->shouldAutoRegisterOnTrackSave()) {
            return;
        }

        $event = $observer->getEvent();
        $track = $event->getTrack();
        if (!$track instanceof Track || !(int) $track->getId()) {
            return;
        }

        $trackingNumber = trim((string) $track->getTrackNumber());
        if ($trackingNumber === '') {
            return;
        }

        try {
            $shipment = $track->getShipment();
            $order = $shipment->getOrder();
            $storeId = (int) $order->getStoreId();
            $this->queueManager->enqueue(Job::TYPE_REGISTER, [
                'track_id' => (int) $track->getId(),
                'tracking_number' => $trackingNumber,
                'shipment_id' => (int) $shipment->getId(),
                'order_id' => (int) $order->getId(),
            ], $storeId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to enqueue tracking registration job', [
                'track_id' => $track->getId(),
                'exception' => $e,
            ]);
        }
    }
}
