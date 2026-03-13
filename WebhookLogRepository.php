<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\App\ResourceConnection;

class WebhookLogRepository
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName('pynarae_tracking_webhook_log'), $data);
    }
}
