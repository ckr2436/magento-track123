<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Block;

use Magento\Framework\View\Element\Template;
use Pynarae\Tracking\Model\Config;

class Result extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLookupResult(): ?array
    {
        $value = $this->getData('lookup_result');
        return is_array($value) ? $value : null;
    }

    public function getLookupError(): ?string
    {
        $value = $this->getData('lookup_error');
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function shouldShowExternalLink(): bool
    {
        return $this->config->shouldShowExternalQueryLink((int) $this->_storeManager->getStore()->getId());
    }

    public function isTrackingOnlyMode(): bool
    {
        $result = $this->getLookupResult();
        return is_array($result) && (($result['lookup_mode'] ?? '') === 'tracking');
    }
}
