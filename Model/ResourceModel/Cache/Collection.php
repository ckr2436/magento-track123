<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\ResourceModel\Cache;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Pynarae\Tracking\Model\Cache;
use Pynarae\Tracking\Model\ResourceModel\Cache as CacheResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Cache::class, CacheResource::class);
    }
}
