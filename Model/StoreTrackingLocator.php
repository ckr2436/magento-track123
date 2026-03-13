<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;

class StoreTrackingLocator
{
    public function __construct(private readonly TrackCollectionFactory $trackCollectionFactory)
    {
    }

    /**
     * @return array<int, Track>
     * @throws LocalizedException
     */
    public function locate(string $trackingNumber): array
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Tracking number is required.'));
        }

        $collection = $this->trackCollectionFactory->create();
        $collection->addFieldToFilter('track_number', $trackingNumber);
        $collection->setOrder('entity_id', 'DESC');

        $tracks = [];
        /** @var Track $track */
        foreach ($collection as $track) {
            if (!(int) $track->getId()) {
                continue;
            }
            $tracks[] = $track;
        }

        if ($tracks === []) {
            throw new LocalizedException(__('We could not find a shipment with that tracking number in this store.'));
        }

        return $tracks;
    }
}
