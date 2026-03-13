<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Model\AbstractModel;
use Pynarae\Tracking\Model\ResourceModel\Job as JobResource;

class Job extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const TYPE_REGISTER = 'register';
    public const TYPE_QUERY = 'query';

    protected function _construct(): void
    {
        $this->_init(JobResource::class);
    }
}
