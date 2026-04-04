<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Pynarae\Tracking\Model\ResourceModel\Job as JobResource;
use Pynarae\Tracking\Model\ResourceModel\Job\CollectionFactory as JobCollectionFactory;

class QueueManager
{
    public function __construct(
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly JobCollectionFactory $jobCollectionFactory,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function enqueue(string $jobType, array $context, ?int $storeId = null): Job
    {
        $providerCode = (string)($context['provider_code'] ?? $this->config->getDefaultProvider($storeId));
        $dedupeHash = hash('sha256', $jobType . '|' . $providerCode . '|' . ((string) ($context['track_id'] ?? '0')) . '|' . ((string) ($context['tracking_number'] ?? '')));
        $existing = $this->findPendingByHash($dedupeHash);
        if ($existing) {
            return $existing;
        }

        $job = $this->jobFactory->create();
        $job->addData([
            'job_type' => $jobType,
            'store_id' => $storeId,
            'order_id' => $context['order_id'] ?? null,
            'shipment_id' => $context['shipment_id'] ?? null,
            'track_id' => $context['track_id'] ?? null,
            'provider_code' => $providerCode,
            'provider_ref' => $context['provider_ref'] ?? null,
            'tracking_number' => $context['tracking_number'] ?? null,
            'payload_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => Job::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => $this->config->getRetryMaxAttempts($storeId),
            'next_run_at' => $this->dateTime->gmtDate(),
            'unique_hash' => $dedupeHash,
        ]);
        $this->jobResource->save($job);

        return $job;
    }

    private function findPendingByHash(string $hash): ?Job
    {
        $collection = $this->jobCollectionFactory->create();
        $collection->addFieldToFilter('unique_hash', $hash);
        $collection->addFieldToFilter('status', ['in' => [Job::STATUS_PENDING, Job::STATUS_PROCESSING]]);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }
}
