<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\TrackingCacheManager;
use Pynarae\Tracking\Model\WebhookLogRepository;
use Pynarae\Tracking\Model\WebhookSignatureVerifier;

class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly WebhookSignatureVerifier $signatureVerifier,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly WebhookLogRepository $webhookLogRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->config->isWebhookEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['ok' => false, 'message' => 'Webhook disabled']);
        }

        $method = strtoupper((string) $this->getRequest()->getMethod());
        $rawBody = (string) $this->getRequest()->getContent();
        if ($method !== 'POST' || trim($rawBody) === '') {
            return $result->setData([
                'ok' => true,
                'message' => 'Webhook endpoint reachable.',
                'method' => $method,
            ]);
        }

        $payload = json_decode($rawBody, true);
        $timestamp = (string) $this->getRequest()->getHeader('X-Track123-Timestamp');
        $signature = (string) $this->getRequest()->getHeader('X-Track123-Signature');
        $verified = $this->signatureVerifier->verify($rawBody, $timestamp, $signature);

        $logData = [
            'timestamp_header' => $timestamp,
            'signature_header' => $signature,
            'payload_hash' => hash('sha256', $rawBody),
            'is_verified' => $verified ? 1 : 0,
            'process_status' => 'received',
            'error_message' => null,
            'payload_json' => $this->config->shouldLogWebhookPayloads() ? $rawBody : null,
        ];

        try {
            if (!is_array($payload)) {
                throw new \RuntimeException('Invalid webhook JSON payload.');
            }

            if (!$verified && $this->config->isStrictSignatureValidation()) {
                throw new \RuntimeException('Webhook signature validation failed.');
            }

            $trackingNumber = (string) ($payload['trackingNumber'] ?? $payload['trackNo'] ?? '');
            $this->trackingCacheManager->upsertFromTrack123Payload($payload, [
                'tracking_number' => $trackingNumber,
            ]);
            $logData['process_status'] = 'processed';
        } catch (\Throwable $e) {
            $logData['process_status'] = 'failed';
            $logData['error_message'] = mb_substr($e->getMessage(), 0, 65535);
            $this->logger->error('Track123 webhook processing failed', ['exception' => $e]);
            $this->webhookLogRepository->insert($logData);
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'message' => $e->getMessage()]);
        }

        $this->webhookLogRepository->insert($logData);
        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
