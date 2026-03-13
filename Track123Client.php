<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;

class Track123Client
{
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verificationContext
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function registerTrackings(array $payload, ?int $storeId = null, array $verificationContext = []): array
    {
        foreach ($payload as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload[$index] = $this->injectVerificationIntoTrackingPayload($row, $verificationContext);
        }

        return $this->post('/track/import', $payload, $storeId);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verificationContext
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function queryTrackings(array $payload, ?int $storeId = null, array $verificationContext = []): array
    {
        $payload = $this->injectVerificationIntoQueryPayload($payload, $verificationContext);
        return $this->post('/track/query', $payload, $storeId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function detectCourier(array $payload, ?int $storeId = null): array
    {
        return $this->post('/courier/detection', $payload, $storeId);
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $payload
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function post(string $path, array $payload, ?int $storeId = null): array
    {
        $secret = $this->config->getApiSecret($storeId);
        if ($secret === '') {
            throw new LocalizedException(__('Track123 API secret is not configured.'));
        }

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getRequestTimeout($storeId));
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->getConnectTimeout($storeId));
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Track123-Api-Secret', $secret);

        $url = $this->config->getApiBaseUrl($storeId) . $path;
        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $curl->post($url, $body);
        } catch (\Throwable $e) {
            $this->logger->error('Track123 HTTP transport error', [
                'path' => $path,
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Track123 request failed: %1', $e->getMessage()));
        }

        $status = (int)$curl->getStatus();
        $responseBody = (string)$curl->getBody();
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->logger->error('Track123 returned invalid JSON', [
                'path' => $path,
                'status' => $status,
                'body' => $responseBody,
            ]);
            throw new LocalizedException(__('Track123 returned invalid JSON. HTTP %1', $status));
        }

        $verificationChallenge = $this->detectAdditionalVerificationChallenge($decoded);
        if ($verificationChallenge !== null) {
            throw new AdditionalVerificationRequiredException(
                __('Additional shipment verification is required before tracking can be retrieved.'),
                $verificationChallenge,
                []
            );
        }

        if ($status < 200 || $status >= 300 || $this->isBusinessErrorResponse($decoded)) {
            $message = $this->extractErrorMessage($decoded);
            $this->logger->error('Track123 returned an error', [
                'path' => $path,
                'status' => $status,
                'response' => $decoded,
            ]);
            throw new LocalizedException(__('Track123 error: %1', $message));
        }

        if ($this->config->isDebug($storeId)) {
            $this->logger->info('Track123 request success', [
                'path' => $path,
                'status' => $status,
            ]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verificationContext
     * @return array<string, mixed>
     */
    private function injectVerificationIntoTrackingPayload(array $payload, array $verificationContext): array
    {
        $postalCode = trim((string)($verificationContext['postal_code'] ?? ''));
        $phoneSuffix = trim((string)($verificationContext['phone_suffix'] ?? ''));

        if ($postalCode !== '' && empty($payload['postalCode'])) {
            $payload['postalCode'] = $postalCode;
        }

        if ($phoneSuffix !== '') {
            if (empty($payload['phoneSuffix'])) {
                $payload['phoneSuffix'] = $phoneSuffix;
            }

            $extendFieldMap = [];
            if (isset($payload['extendFieldMap']) && is_array($payload['extendFieldMap'])) {
                $extendFieldMap = $payload['extendFieldMap'];
            }
            if (empty($extendFieldMap['phoneSuffix'])) {
                $extendFieldMap['phoneSuffix'] = $phoneSuffix;
            }
            $payload['extendFieldMap'] = $extendFieldMap;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verificationContext
     * @return array<string, mixed>
     */
    private function injectVerificationIntoQueryPayload(array $payload, array $verificationContext): array
    {
        $postalCode = trim((string)($verificationContext['postal_code'] ?? ''));
        $phoneSuffix = trim((string)($verificationContext['phone_suffix'] ?? ''));

        if ($postalCode !== '' && empty($payload['postalCode'])) {
            $payload['postalCode'] = $postalCode;
        }

        if ($phoneSuffix !== '') {
            if (empty($payload['phoneSuffix'])) {
                $payload['phoneSuffix'] = $phoneSuffix;
            }

            $extendFieldMap = [];
            if (isset($payload['extendFieldMap']) && is_array($payload['extendFieldMap'])) {
                $extendFieldMap = $payload['extendFieldMap'];
            }
            if (empty($extendFieldMap['phoneSuffix'])) {
                $extendFieldMap['phoneSuffix'] = $phoneSuffix;
            }
            $payload['extendFieldMap'] = $extendFieldMap;
        }

        return $payload;
    }

    /**
     * 返回：
     * - null  => 不是附加验证挑战
     * - []    => 是挑战，但字段不明确（此时上层按 postalCode 优先）
     * - ['postal_code' => true] / ['phone_suffix' => true] / 两者都有
     *
     * @param array<string, mixed> $decoded
     * @return array{postal_code?:bool,phone_suffix?:bool}|array{}|null
     */
    private function detectAdditionalVerificationChallenge(array $decoded): ?array
    {
        $strings = $this->flattenStrings($decoded);
        $haystack = mb_strtolower(implode(' | ', $strings));

        $requiresPostal = false;
        $requiresPhone = false;
        $genericChallenge = false;

        if (preg_match('/postal[\s_-]?code|zip[\s_-]?code|zipcode/', $haystack)) {
            $requiresPostal = true;
        }

        if (preg_match('/phone[\s_-]?suffix|phonesuffix|last\s*4|last\s*four|phone number suffix/', $haystack)) {
            $requiresPhone = true;
        }

        if (preg_match('/additional tracking fields?|additional fields?|authenticate your api request|verification required|extra destination verification|required fields?/', $haystack)) {
            $genericChallenge = true;
        }

        $explicitFields = $this->extractFieldHints($decoded);
        foreach ($explicitFields as $field) {
            if (in_array($field, ['postalcode', 'postal_code', 'zipcode', 'zip'], true)) {
                $requiresPostal = true;
            }
            if (in_array($field, ['phonesuffix', 'phone_suffix'], true)) {
                $requiresPhone = true;
            }
        }

        if (!$requiresPostal && !$requiresPhone && !$genericChallenge) {
            return null;
        }

        $result = [];
        if ($requiresPostal) {
            $result['postal_code'] = true;
        }
        if ($requiresPhone) {
            $result['phone_suffix'] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return string
     */
    private function extractErrorMessage(array $decoded): string
    {
        foreach (['message', 'msg', 'errorMessage', 'detail', 'error'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
                return trim((string)$decoded[$key]);
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['message', 'msg', 'errorMessage', 'detail'] as $key) {
                if (isset($decoded['data'][$key]) && is_scalar($decoded['data'][$key]) && trim((string)$decoded['data'][$key]) !== '') {
                    return trim((string)$decoded['data'][$key]);
                }
            }
        }

        return 'Unknown Track123 error.';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function isBusinessErrorResponse(array $decoded): bool
    {
        if (isset($decoded['success']) && $decoded['success'] === false) {
            return true;
        }

        if (isset($decoded['error']) && is_scalar($decoded['error']) && trim((string)$decoded['error']) !== '') {
            return true;
        }

        $code = $decoded['code'] ?? $decoded['status'] ?? null;
        $hasMessage = isset($decoded['message']) || isset($decoded['msg']) || isset($decoded['errorMessage']) || isset($decoded['detail']);

        if ($hasMessage && is_numeric($code)) {
            $numericCode = (int)$code;
            if (!in_array($numericCode, [0, 200], true)) {
                return true;
            }
        }

        if (isset($decoded['data']['success']) && $decoded['data']['success'] === false) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function flattenStrings(mixed $value): array
    {
        $result = [];

        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                $result[] = $value;
            }
            return $result;
        }

        if (!is_array($value)) {
            return $result;
        }

        foreach ($value as $item) {
            foreach ($this->flattenStrings($item) as $string) {
                $result[] = $string;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, string>
     */
    private function extractFieldHints(array $decoded): array
    {
        $fields = [];
        $keysToInspect = ['requiredFields', 'additionalFields', 'missingFields', 'fields'];

        $walker = function (mixed $value) use (&$walker, &$fields, $keysToInspect): void {
            if (!is_array($value)) {
                return;
            }

            foreach ($value as $key => $item) {
                if (in_array((string)$key, $keysToInspect, true) && is_array($item)) {
                    foreach ($item as $field) {
                        if (is_scalar($field)) {
                            $fields[] = mb_strtolower(trim((string)$field));
                        }
                    }
                }

                $walker($item);
            }
        };

        $walker($decoded);

        return array_values(array_unique(array_filter($fields)));
    }
}
