<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\ResourceModel\Job;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Pynarae\Tracking\Model\Job;
use Pynarae\Tracking\Model\ResourceModel\Job as JobResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Job::class, JobResource::class);
    }
}
