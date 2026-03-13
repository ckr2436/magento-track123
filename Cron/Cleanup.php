<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Config;

class Cleanup
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock('pynarae_tracking_cleanup', 3600)) {
            return;
        }

        try {
            $days = $this->config->getCleanupDays();
            $connection = $this->resourceConnection->getConnection();
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

            $connection->delete(
                $this->resourceConnection->getTableName('pynarae_tracking_webhook_log'),
                ['created_at < ?' => $cutoff]
            );

            $connection->delete(
                $this->resourceConnection->getTableName('pynarae_tracking_job'),
                ['updated_at < ?' => $cutoff, 'status IN (?)' => ['success', 'failed']]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Pynarae tracking cleanup failed', ['exception' => $e]);
        } finally {
            $this->lockManager->unlock('pynarae_tracking_cleanup');
        }
    }
}
