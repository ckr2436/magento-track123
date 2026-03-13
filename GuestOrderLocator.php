<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class GuestOrderLocator
{
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PhoneNormalizer $phoneNormalizer
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function locate(string $incrementId, string $emailOrPhone): Order
    {
        $incrementId = trim($incrementId);
        $emailOrPhone = trim($emailOrPhone);

        if ($incrementId === '' || $emailOrPhone === '') {
            throw new LocalizedException(__('Order number and email or phone are required.'));
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToSelect('*');
        $collection->addFieldToFilter('increment_id', $incrementId);
        $collection->setPageSize(1);

        /** @var Order $order */
        $order = $collection->getFirstItem();
        if (!$order->getId()) {
            throw new LocalizedException(__('We could not find an order that matches those details.'));
        }

        $emailMatch = mb_strtolower((string) $order->getCustomerEmail()) === mb_strtolower($emailOrPhone);
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $phoneMatch = $this->phoneNormalizer->matches($emailOrPhone, (string) ($billing?->getTelephone() ?: ''))
            || $this->phoneNormalizer->matches($emailOrPhone, (string) ($shipping?->getTelephone() ?: ''));

        if (!$emailMatch && !$phoneMatch) {
            throw new LocalizedException(__('We could not verify the email or phone for this order.'));
        }

        return $order;
    }
}
