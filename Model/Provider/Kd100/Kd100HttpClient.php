<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Kd100;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class Kd100HttpClient
{
    public function __construct(
        private readonly Kd100Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createTracking(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/v1/tracking/create', $payload, $storeId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function realtimeTracking(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/v1/tracking/realtime', $payload, $storeId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function detectCarrier(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/v1/carriers/detect', $payload, $storeId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload, ?int $storeId = null): array
    {
        $apiKey = trim($this->config->getApiKey($storeId));
        $apiSecret = trim($this->config->getApiSecret($storeId));

        if ($apiKey === '' || $apiSecret === '') {
            throw new LocalizedException(__('KeyDelivery API key and secret are not configured.'));
        }

        $baseUrl = $this->config->getBaseUrl($storeId);
        if ($baseUrl === '') {
            throw new LocalizedException(__('KeyDelivery base URL is not configured.'));
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = strtoupper(md5($body . $apiKey . $apiSecret));
        $url = $baseUrl . $path;

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getRequestTimeout($storeId));
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->getConnectTimeout($storeId));
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('API-Key', $apiKey);
        $curl->addHeader('signature', $signature);

        try {
            $curl->post($url, $body);
        } catch (\Throwable $e) {
            $this->logger->error('KeyDelivery HTTP transport error', [
                'url' => $url,
                'path' => $path,
                'exception' => $e,
            ]);
            throw new LocalizedException(__('KeyDelivery request failed: %1', $e->getMessage()));
        }

        $status = (int)$curl->getStatus();
        $responseBody = (string)$curl->getBody();
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->logger->error('KeyDelivery returned invalid JSON', [
                'url' => $url,
                'path' => $path,
                'status' => $status,
                'body_length' => strlen($responseBody),
            ]);
            throw new LocalizedException(__('KeyDelivery returned invalid JSON. HTTP %1', $status));
        }

        if ($status < 200 || $status >= 300 || $this->isBusinessError($decoded)) {
            $message = $this->extractErrorMessage($decoded);
            $this->logger->error('KeyDelivery returned an error', [
                'url' => $url,
                'path' => $path,
                'status' => $status,
                'response' => $this->sanitizeResponse($decoded),
            ]);
            throw new LocalizedException(__('KeyDelivery error: HTTP %1: %2', $status, $message));
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function isBusinessError(array $decoded): bool
    {
        if (isset($decoded['success']) && $decoded['success'] === false) {
            return true;
        }

        $code = $decoded['code'] ?? null;
        if (is_numeric($code)) {
            $numericCode = (int)$code;
            if (!in_array($numericCode, [0, 200], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): string
    {
        foreach (['message', 'msg', 'errorMessage', 'detail', 'error'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
                return trim((string)$decoded[$key]);
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['message', 'msg', 'errorMessage', 'detail', 'error'] as $key) {
                if (isset($decoded['data'][$key]) && is_scalar($decoded['data'][$key]) && trim((string)$decoded['data'][$key]) !== '') {
                    return trim((string)$decoded['data'][$key]);
                }
            }
        }

        return 'Unknown KeyDelivery error.';
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function sanitizeResponse(array $decoded): array
    {
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $decoded;
        }

        $json = preg_replace('/("(?:phone|email|address|ship_from|ship_to)"\s*:\s*")[^"]*(")/i', '$1[REDACTED]$2', $json) ?: $json;
        $result = json_decode($json, true);

        return is_array($result) ? $result : $decoded;
    }
}
