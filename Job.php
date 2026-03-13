<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Job extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('pynarae_tracking_job', 'entity_id');
    }
}
