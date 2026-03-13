<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track as TrackResource;

class TrackLoader
{
    public function __construct(
        private readonly TrackFactory $trackFactory,
        private readonly TrackResource $trackResource
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function load(int $trackId): Track
    {
        $track = $this->trackFactory->create();
        $this->trackResource->load($track, $trackId);
        if (!$track->getId()) {
            throw new NoSuchEntityException(__('Shipment track %1 no longer exists.', $trackId));
        }

        return $track;
    }
}
