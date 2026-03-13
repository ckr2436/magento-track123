<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Logger;

use Monolog\Logger;
use Magento\Framework\Logger\Handler\Base;

class Handler extends Base
{
    protected $loggerType = Logger::INFO;

    protected $fileName = '/var/log/pynarae_tracking.log';
}
