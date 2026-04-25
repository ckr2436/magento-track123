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
use Pynarae\Tracking\Model\Dto\WebhookRequest;
use Pynarae\Tracking\Model\Provider\Kd100\Kd100Provider;
use Pynarae\Tracking\Model\TrackingCacheManager;
use Pynarae\Tracking\Model\WebhookLogRepository;

class Kd100 extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Kd100Provider $provider,
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
            return $result->setHttpResponseCode(404)->setData([
                'code' => 404,
                'message' => 'Webhook disabled',
            ]);
        }

        $method = strtoupper((string)$this->getRequest()->getMethod());
        $rawBody = (string)$this->getRequest()->getContent();

        if ($method !== 'POST' || trim($rawBody) === '') {
            return $result->setData([
                'code' => 200,
                'message' => 'Webhook endpoint reachable.',
                'method' => $method,
            ]);
        }

        $payload = json_decode($rawBody, true);
        $headers = [
            'signature' => (string)$this->getRequest()->getHeader('signature'),
            'x-kd100-signature' => (string)$this->getRequest()->getHeader('X-KD100-Signature'),
        ];
        if ($headers['signature'] === '' && $headers['x-kd100-signature'] !== '') {
            $headers['signature'] = $headers['x-kd100-signature'];
        }

        $webhookRequest = new WebhookRequest(
            providerCode: 'kd100',
            method: $method,
            path: (string)$this->getRequest()->getPathInfo(),
            headers: $headers,
            rawBody: $rawBody,
            jsonBody: is_array($payload) ? $payload : null
        );

        $verified = $this->provider->verifyWebhook($webhookRequest);

        $logData = [
            'provider_code' => 'kd100',
            'provider_message_id' => null,
            'provider_topic' => null,
            'timestamp_header' => null,
            'signature_header' => $headers['signature'] !== '' ? hash('sha256', $headers['signature']) : null,
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

            foreach ($this->provider->parseWebhook($webhookRequest) as $providerResult) {
                $this->trackingCacheManager->upsertFromProviderResult($providerResult, [
                    'tracking_number' => $providerResult->trackingNumber,
                ]);
            }

            $logData['process_status'] = 'processed';
        } catch (\Throwable $e) {
            $logData['process_status'] = 'failed';
            $logData['error_message'] = mb_substr($e->getMessage(), 0, 65535);

            $this->logger->error('KeyDelivery webhook processing failed', [
                'exception' => $e,
            ]);

            $this->webhookLogRepository->insert($logData);

            return $result->setHttpResponseCode(400)->setData([
                'code' => 400,
                'message' => $e->getMessage(),
            ]);
        }

        $this->webhookLogRepository->insert($logData);

        return $result->setData([
            'code' => 200,
            'message' => 'OK',
        ]);
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
