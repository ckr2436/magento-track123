<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Model\AbstractModel;
use Pynarae\Tracking\Model\ResourceModel\Cache as CacheResource;

class Cache extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CacheResource::class);
    }
}
