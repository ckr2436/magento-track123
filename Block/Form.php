<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Block;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Pynarae\Tracking\Model\Config;

class Form extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly FormKey $formKey,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getPostActionUrl(): string
    {
        return $this->getUrl('', ['_direct' => 'track-order/lookup']);
    }

    public function getFieldValue(string $key): string
    {
        $values = $this->getData('form_values');
        return is_array($values) ? (string) ($values[$key] ?? '') : '';
    }

    public function getActiveMode(): string
    {
        $mode = $this->getFieldValue('query_mode');
        return in_array($mode, ['order', 'tracking'], true) ? $mode : 'order';
    }

    public function getLookupError(): ?string
    {
        $value = $this->getData('lookup_error');
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isModuleEnabled(): bool
    {
        return $this->config->isEnabled((int) $this->_storeManager->getStore()->getId());
    }
}
