<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\ResourceModel\Job as JobResource;
use Pynarae\Tracking\Model\ResourceModel\Job\CollectionFactory as JobCollectionFactory;

class QueueProcessor
{
    public function __construct(
        private readonly JobCollectionFactory $jobCollectionFactory,
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly TrackLoader $trackLoader,
        private readonly TrackingSynchronizer $trackingSynchronizer,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processBatch(): int
    {
        $processed = 0;
        $collection = $this->jobCollectionFactory->create();
        $collection->addFieldToFilter('status', Job::STATUS_PENDING);
        $collection->addFieldToFilter('next_run_at', ['lteq' => $this->dateTime->gmtDate()]);
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($this->config->getQueueBatchSize());

        foreach ($collection as $job) {
            try {
                $this->markProcessing($job);
                $this->processSingleJob($job);
                $this->markSuccess($job);
                $processed++;
            } catch (\Throwable $e) {
                $this->markFailure($job, $e);
                $processed++;
            }
        }

        return $processed;
    }

    private function processSingleJob(Job $job): void
    {
        $trackId = (int) $job->getData('track_id');
        if ($trackId <= 0) {
            throw new \RuntimeException('Missing track_id in queue job.');
        }

        $track = $this->trackLoader->load($trackId);
        $providerCode = trim((string)$job->getData('provider_code'));
        $forcedProviderCode = $providerCode !== '' ? $providerCode : null;

        if ($job->getData('job_type') === Job::TYPE_REGISTER) {
            $this->trackingSynchronizer->registerTrack($track, [], $forcedProviderCode);
            $this->enqueueFollowupQueryIfNeeded($job);
            return;
        }

        if ($job->getData('job_type') === Job::TYPE_QUERY) {
            $this->trackingSynchronizer->queryTrack($track, [], $forcedProviderCode);
            return;
        }

        throw new \RuntimeException('Unknown job type: ' . (string) $job->getData('job_type'));
    }

    private function enqueueFollowupQueryIfNeeded(Job $registerJob): void
    {
        $job = $this->jobFactory->create();
        $job->setData('job_type', Job::TYPE_QUERY);
        $job->setData('store_id', $registerJob->getData('store_id'));
        $job->setData('order_id', $registerJob->getData('order_id'));
        $job->setData('shipment_id', $registerJob->getData('shipment_id'));
        $job->setData('track_id', $registerJob->getData('track_id'));
        $job->setData('provider_code', $registerJob->getData('provider_code'));
        $job->setData('provider_ref', $registerJob->getData('provider_ref'));
        $job->setData('tracking_number', $registerJob->getData('tracking_number'));
        $job->setData('payload_json', $registerJob->getData('payload_json'));
        $job->setData('status', Job::STATUS_PENDING);
        $job->setData('attempts', 0);
        $job->setData('next_run_at', $this->dateTime->gmtDate());
        $job->setData('locked_at', null);
        $job->setData('last_error', null);
        $job->setData('unique_hash', hash('sha256', Job::TYPE_QUERY . '|' . (string)$registerJob->getData('provider_code') . '|' . (string) $registerJob->getData('track_id') . '|' . (string) $registerJob->getData('tracking_number')));
        $this->jobResource->save($job);
    }

    private function markProcessing(Job $job): void
    {
        $job->setData('status', Job::STATUS_PROCESSING);
        $job->setData('locked_at', $this->dateTime->gmtDate());
        $job->setData('attempts', ((int) $job->getData('attempts')) + 1);
        $this->jobResource->save($job);
    }

    private function markSuccess(Job $job): void
    {
        $job->setData('status', Job::STATUS_SUCCESS);
        $job->setData('locked_at', null);
        $job->setData('last_error', null);
        $job->setData('next_run_at', $this->dateTime->gmtDate());
        $this->jobResource->save($job);
    }

    private function markFailure(Job $job, \Throwable $e): void
    {
        $attempts = (int) $job->getData('attempts');
        $maxAttempts = (int) $job->getData('max_attempts');
        $trackId = (int) $job->getData('track_id');

        if ($trackId > 0) {
            $this->trackingCacheManager->markError($trackId, $e->getMessage());
        }

        if ($attempts >= $maxAttempts || $e instanceof NoSuchEntityException) {
            $job->setData('status', Job::STATUS_FAILED);
            $job->setData('next_run_at', $this->dateTime->gmtDate());
        } else {
            $backoffMinutes = $this->config->getRetryBackoffMinutes();
            $delayMinutes = $backoffMinutes * max(1, $attempts);
            $job->setData('status', Job::STATUS_PENDING);
            $job->setData('next_run_at', gmdate('Y-m-d H:i:s', time() + ($delayMinutes * 60)));
        }

        $job->setData('locked_at', null);
        $job->setData('last_error', mb_substr($e->getMessage(), 0, 65535));
        $this->jobResource->save($job);

        $this->logger->error('Tracking queue job failed', [
            'job_id' => $job->getId(),
            'job_type' => $job->getData('job_type'),
            'exception' => $e,
        ]);
    }
}
