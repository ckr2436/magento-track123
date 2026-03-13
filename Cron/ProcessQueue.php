<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Cron;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\QueueProcessor;

class ProcessQueue
{
    public function __construct(
        private readonly QueueProcessor $queueProcessor,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock('pynarae_tracking_process_queue', 55)) {
            return;
        }

        try {
            $processed = $this->queueProcessor->processBatch();
            if ($processed > 0) {
                $this->logger->info('Pynarae tracking queue processed', ['count' => $processed]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pynarae tracking queue crashed', ['exception' => $e]);
        } finally {
            $this->lockManager->unlock('pynarae_tracking_process_queue');
        }
    }
}
