<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'pynarae_tracking/general/enabled';
    public const XML_PATH_DEBUG = 'pynarae_tracking/general/debug';
    public const XML_PATH_SHOW_EXTERNAL_QUERY_LINK = 'pynarae_tracking/general/show_external_query_link';
    public const XML_PATH_LIVE_REFRESH = 'pynarae_tracking/general/use_live_refresh_on_lookup';
    public const XML_PATH_CACHE_TTL = 'pynarae_tracking/general/cache_ttl_minutes';
    public const XML_PATH_STALE_AFTER = 'pynarae_tracking/general/query_stale_after_minutes';
    public const XML_PATH_MAX_EVENTS = 'pynarae_tracking/general/max_events_display';
    public const XML_PATH_DEFAULT_PROVIDER = 'pynarae_tracking/general/default_provider';

    public const XML_PATH_TRACK123_ENABLED = 'pynarae_tracking/providers_track123/enabled';
    public const XML_PATH_TRACK123_BASE_URL = 'pynarae_tracking/providers_track123/base_url';
    public const XML_PATH_TRACK123_API_SECRET = 'pynarae_tracking/providers_track123/api_secret';
    public const XML_PATH_TRACK123_REQUEST_TIMEOUT = 'pynarae_tracking/providers_track123/request_timeout';
    public const XML_PATH_TRACK123_CONNECT_TIMEOUT = 'pynarae_tracking/providers_track123/connect_timeout';
    public const XML_PATH_TRACK123_AUTO_DETECT_CARRIER = 'pynarae_tracking/providers_track123/auto_detect_carrier';
    public const XML_PATH_TRACK123_CARRIER_MAPPING = 'pynarae_tracking/providers_track123/carrier_mapping';

    public const XML_PATH_SHIP24_ENABLED = 'pynarae_tracking/providers_ship24/enabled';
    public const XML_PATH_SHIP24_BASE_URL = 'pynarae_tracking/providers_ship24/base_url';
    public const XML_PATH_SHIP24_API_KEY = 'pynarae_tracking/providers_ship24/api_key';
    public const XML_PATH_SHIP24_WEBHOOK_SECRET = 'pynarae_tracking/providers_ship24/webhook_secret';
    public const XML_PATH_SHIP24_REQUEST_TIMEOUT = 'pynarae_tracking/providers_ship24/request_timeout';
    public const XML_PATH_SHIP24_CONNECT_TIMEOUT = 'pynarae_tracking/providers_ship24/connect_timeout';
    public const XML_PATH_SHIP24_CREATE_AND_TRACK = 'pynarae_tracking/providers_ship24/use_create_and_track_endpoint';
    public const XML_PATH_SHIP24_RESTRICT_COURIER_CODE = 'pynarae_tracking/providers_ship24/restrict_tracking_to_courier_code';

    public const XML_PATH_AUTO_REGISTER = 'pynarae_tracking/sync/auto_register_on_track_save';
    public const XML_PATH_QUEUE_BATCH_SIZE = 'pynarae_tracking/sync/process_queue_batch_size';
    public const XML_PATH_RETRY_MAX_ATTEMPTS = 'pynarae_tracking/sync/retry_max_attempts';
    public const XML_PATH_RETRY_BACKOFF_MINUTES = 'pynarae_tracking/sync/retry_backoff_minutes';

    public const XML_PATH_WEBHOOK_ENABLED = 'pynarae_tracking/webhook/enabled';
    public const XML_PATH_WEBHOOK_STRICT = 'pynarae_tracking/webhook/strict_signature_validation';
    public const XML_PATH_WEBHOOK_SIGNATURE_MODE = 'pynarae_tracking/webhook/signature_mode';
    public const XML_PATH_WEBHOOK_TOLERANCE = 'pynarae_tracking/webhook/signature_tolerance_seconds';
    public const XML_PATH_WEBHOOK_LOG_PAYLOADS = 'pynarae_tracking/webhook/log_payloads';
    public const XML_PATH_WEBHOOK_CLEANUP_DAYS = 'pynarae_tracking/webhook/cleanup_days';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_ENABLED, $storeId);
    }

    public function isDebug(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_DEBUG, $storeId);
    }

    public function shouldShowExternalQueryLink(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_SHOW_EXTERNAL_QUERY_LINK, $storeId);
    }

    public function useLiveRefreshOnLookup(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_LIVE_REFRESH, $storeId);
    }

    public function getCacheTtlMinutes(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_CACHE_TTL, $storeId));
    }

    public function getQueryStaleAfterMinutes(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_STALE_AFTER, $storeId));
    }

    public function getMaxEventsDisplay(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_MAX_EVENTS, $storeId));
    }

    public function getDefaultProvider(?int $storeId = null): string
    {
        $value = trim((string)$this->getValue(self::XML_PATH_DEFAULT_PROVIDER, $storeId));
        return $value !== '' ? $value : 'track123';
    }

    public function isTrack123Enabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_TRACK123_ENABLED, $storeId);
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getValue(self::XML_PATH_TRACK123_BASE_URL, $storeId), '/');
    }

    public function getApiSecret(?int $storeId = null): string
    {
        return $this->getDecryptedValue(self::XML_PATH_TRACK123_API_SECRET, $storeId);
    }

    public function getRequestTimeout(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_TRACK123_REQUEST_TIMEOUT, $storeId));
    }

    public function getConnectTimeout(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_TRACK123_CONNECT_TIMEOUT, $storeId));
    }

    public function shouldAutoDetectCarrier(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_TRACK123_AUTO_DETECT_CARRIER, $storeId);
    }

    public function getCarrierMappingRaw(?int $storeId = null): string
    {
        return (string) $this->getValue(self::XML_PATH_TRACK123_CARRIER_MAPPING, $storeId);
    }

    public function isShip24Enabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_SHIP24_ENABLED, $storeId);
    }

    public function getShip24BaseUrl(?int $storeId = null): string
    {
        return rtrim((string)$this->getValue(self::XML_PATH_SHIP24_BASE_URL, $storeId), '/');
    }

    public function getShip24ApiKey(?int $storeId = null): string
    {
        return $this->getDecryptedValue(self::XML_PATH_SHIP24_API_KEY, $storeId);
    }

    public function getShip24WebhookSecret(?int $storeId = null): string
    {
        return $this->getDecryptedValue(self::XML_PATH_SHIP24_WEBHOOK_SECRET, $storeId);
    }

    public function getShip24RequestTimeout(?int $storeId = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_SHIP24_REQUEST_TIMEOUT, $storeId));
    }

    public function getShip24ConnectTimeout(?int $storeId = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_SHIP24_CONNECT_TIMEOUT, $storeId));
    }

    public function useShip24CreateAndTrackEndpoint(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_SHIP24_CREATE_AND_TRACK, $storeId);
    }

    public function restrictShip24TrackingToCourierCode(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_SHIP24_RESTRICT_COURIER_CODE, $storeId);
    }

    public function shouldAutoRegisterOnTrackSave(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_AUTO_REGISTER, $storeId);
    }

    public function getQueueBatchSize(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_QUEUE_BATCH_SIZE, $storeId));
    }

    public function getRetryMaxAttempts(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_RETRY_MAX_ATTEMPTS, $storeId));
    }

    public function getRetryBackoffMinutes(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_RETRY_BACKOFF_MINUTES, $storeId));
    }

    public function isWebhookEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_ENABLED, $storeId);
    }

    public function isStrictSignatureValidation(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_STRICT, $storeId);
    }

    public function getWebhookSignatureMode(?int $storeId = null): string
    {
        return (string) $this->getValue(self::XML_PATH_WEBHOOK_SIGNATURE_MODE, $storeId) ?: 'auto';
    }

    public function getWebhookToleranceSeconds(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_WEBHOOK_TOLERANCE, $storeId));
    }

    public function shouldLogWebhookPayloads(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_LOG_PAYLOADS, $storeId);
    }

    public function getCleanupDays(?int $storeId = null): int
    {
        return max(1, (int) $this->getValue(self::XML_PATH_WEBHOOK_CLEANUP_DAYS, $storeId));
    }

    private function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function isFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function getDecryptedValue(string $path, ?int $storeId = null): string
    {
        $value = trim((string)$this->getValue($path, $storeId));
        if ($value === '') {
            return '';
        }

        $decrypted = trim((string)$this->encryptor->decrypt($value));
        return $decrypted !== '' ? $decrypted : $value;
    }
}
