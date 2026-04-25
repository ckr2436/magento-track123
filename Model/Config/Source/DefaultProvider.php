<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DefaultProvider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'track123', 'label' => __('Track123')],
            ['value' => 'ship24', 'label' => __('Ship24')],
            ['value' => 'kd100', 'label' => __('KeyDelivery / KD100')],
        ];
    }
}
