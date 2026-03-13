<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class AdditionalVerificationRequiredException extends LocalizedException
{
    /**
     * @param array{postal_code?:bool,phone_suffix?:bool} $requiredFields
     * @param array{postal_code?:string,phone_suffix?:string} $prefill
     */
    public function __construct(
        Phrase $phrase,
        private readonly array $requiredFields = [],
        private readonly array $prefill = []
    ) {
        parent::__construct($phrase);
    }

    public function requiresPostalCode(): bool
    {
        return (bool)($this->requiredFields['postal_code'] ?? false);
    }

    public function requiresPhoneSuffix(): bool
    {
        return (bool)($this->requiredFields['phone_suffix'] ?? false);
    }

    /**
     * @return array{postal_code?:bool,phone_suffix?:bool}
     */
    public function getRequiredFields(): array
    {
        return $this->requiredFields;
    }

    /**
     * @return array{postal_code?:string,phone_suffix?:string}
     */
    public function getPrefill(): array
    {
        return $this->prefill;
    }
}
