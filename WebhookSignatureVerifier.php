<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

class WebhookSignatureVerifier
{
    public function __construct(private readonly Config $config)
    {
    }

    public function verify(string $rawBody, string $timestamp, string $signature, ?int $storeId = null): bool
    {
        if (!$this->config->isStrictSignatureValidation($storeId)) {
            return true;
        }

        $secret = $this->config->getApiSecret($storeId);
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $tolerance = $this->config->getWebhookToleranceSeconds($storeId);
        if (ctype_digit($timestamp)) {
            $tsSeconds = strlen($timestamp) > 10 ? (int) floor(((int) $timestamp) / 1000) : (int) $timestamp;
            if (abs(time() - $tsSeconds) > $tolerance) {
                return false;
            }
        }

        $candidates = $this->buildCandidates($secret, $timestamp, $rawBody, $this->config->getWebhookSignatureMode($storeId));
        foreach ($candidates as $candidate) {
            if (hash_equals(strtolower($candidate), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function buildCandidates(string $secret, string $timestamp, string $rawBody, string $mode): array
    {
        $all = [
            'sha256_token_timestamp' => hash('sha256', $secret . $timestamp),
            'sha256_timestamp_token' => hash('sha256', $timestamp . $secret),
            'hmac_timestamp' => hash_hmac('sha256', $timestamp, $secret),
            'hmac_token' => hash_hmac('sha256', $secret, $timestamp),
            'hmac_body_timestamp' => hash_hmac('sha256', $rawBody . $timestamp, $secret),
            'hmac_timestamp_body' => hash_hmac('sha256', $timestamp . $rawBody, $secret),
        ];

        if ($mode === 'auto') {
            return array_values($all);
        }

        return isset($all[$mode]) ? [$all[$mode]] : array_values($all);
    }
}
