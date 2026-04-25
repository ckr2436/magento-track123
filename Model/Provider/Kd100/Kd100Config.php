<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Kd100;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Kd100Config
{
    private const XML_PATH_MODULE_ENABLED = 'pynarae_tracking/general/enabled';
    private const XML_PATH_WEBHOOK_STRICT = 'pynarae_tracking/webhook/strict_signature_validation';

    private const XML_PATH_ENABLED = 'pynarae_tracking/providers_kd100/enabled';
    private const XML_PATH_BASE_URL = 'pynarae_tracking/providers_kd100/base_url';
    private const XML_PATH_API_KEY = 'pynarae_tracking/providers_kd100/api_key';
    private const XML_PATH_API_SECRET = 'pynarae_tracking/providers_kd100/api_secret';
    private const XML_PATH_REQUEST_TIMEOUT = 'pynarae_tracking/providers_kd100/request_timeout';
    private const XML_PATH_CONNECT_TIMEOUT = 'pynarae_tracking/providers_kd100/connect_timeout';
    private const XML_PATH_AUTO_DETECT_CARRIER = 'pynarae_tracking/providers_kd100/auto_detect_carrier';
    private const XML_PATH_CARRIER_MAPPING = 'pynarae_tracking/providers_kd100/carrier_mapping';
    private const XML_PATH_DEFAULT_SHIP_FROM = 'pynarae_tracking/providers_kd100/default_ship_from';
    private const XML_PATH_WEBHOOK_URL = 'pynarae_tracking/providers_kd100/webhook_url';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isModuleEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_MODULE_ENABLED, $storeId);
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_ENABLED, $storeId);
    }

    public function isStrictWebhookValidation(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_STRICT, $storeId);
    }

    public function getBaseUrl(?int $storeId = null): string
    {
        return rtrim((string)$this->getValue(self::XML_PATH_BASE_URL, $storeId), '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        return $this->getDecryptedValue(self::XML_PATH_API_KEY, $storeId);
    }

    public function getApiSecret(?int $storeId = null): string
    {
        return $this->getDecryptedValue(self::XML_PATH_API_SECRET, $storeId);
    }

    public function getRequestTimeout(?int $storeId = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_REQUEST_TIMEOUT, $storeId));
    }

    public function getConnectTimeout(?int $storeId = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_CONNECT_TIMEOUT, $storeId));
    }

    public function shouldAutoDetectCarrier(?int $storeId = null): bool
    {
        return $this->isFlag(self::XML_PATH_AUTO_DETECT_CARRIER, $storeId);
    }

    public function getCarrierMappingRaw(?int $storeId = null): string
    {
        return (string)$this->getValue(self::XML_PATH_CARRIER_MAPPING, $storeId);
    }

    public function getDefaultShipFrom(?int $storeId = null): string
    {
        return trim((string)$this->getValue(self::XML_PATH_DEFAULT_SHIP_FROM, $storeId));
    }

    public function getWebhookUrl(?int $storeId = null): string
    {
        return trim((string)$this->getValue(self::XML_PATH_WEBHOOK_URL, $storeId));
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
