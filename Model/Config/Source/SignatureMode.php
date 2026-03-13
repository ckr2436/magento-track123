<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SignatureMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Auto')],
            ['value' => 'sha256_token_timestamp', 'label' => __('sha256(token + timestamp)')],
            ['value' => 'sha256_timestamp_token', 'label' => __('sha256(timestamp + token)')],
            ['value' => 'hmac_timestamp', 'label' => __('hash_hmac("sha256", timestamp, token)')],
            ['value' => 'hmac_token', 'label' => __('hash_hmac("sha256", token, timestamp)')],
        ];
    }
}
