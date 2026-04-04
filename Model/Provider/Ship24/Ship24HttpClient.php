<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Ship24;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Config;

class Ship24HttpClient
{
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function register(array $payload, ?int $storeId = null): array
    {
        return $this->post('/public/v1/trackers', $payload, $storeId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function query(array $payload, ?int $storeId = null): array
    {
        return $this->post('/public/v1/trackers/track', $payload, $storeId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload, ?int $storeId = null): array
    {
        $apiKey = trim($this->config->getShip24ApiKey($storeId));
        if ($apiKey === '') {
            throw new LocalizedException(__('Ship24 API key is not configured.'));
        }

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getShip24RequestTimeout($storeId));
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->getShip24ConnectTimeout($storeId));
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $apiKey);

        $url = $this->config->getShip24BaseUrl($storeId) . $path;
        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $curl->post($url, $body);
        } catch (\Throwable $e) {
            $this->logger->error('Ship24 HTTP transport error', [
                'url' => $url,
                'path' => $path,
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Ship24 request failed: %1', $e->getMessage()));
        }

        $status = (int)$curl->getStatus();
        $responseBody = (string)$curl->getBody();
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->logger->error('Ship24 returned invalid JSON', [
                'url' => $url,
                'path' => $path,
                'status' => $status,
                'body_length' => strlen($responseBody),
            ]);
            throw new LocalizedException(__('Ship24 returned invalid JSON. HTTP %1', $status));
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->extractErrorMessage($decoded);
            $this->logger->error('Ship24 returned an error', [
                'url' => $url,
                'path' => $path,
                'status' => $status,
                'response' => $decoded,
            ]);
            throw new LocalizedException(__('Ship24 error: HTTP %1: %2', $status, $message));
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): string
    {
        if (isset($decoded['errors'][0]['message']) && is_string($decoded['errors'][0]['message'])) {
            return $decoded['errors'][0]['message'];
        }

        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return 'Unknown Ship24 error.';
    }
}
