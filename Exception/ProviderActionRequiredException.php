<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Exception;

use Magento\Framework\Phrase;

class ProviderActionRequiredException extends ProviderException
{
    /**
     * @param array<string,bool> $requiredFields
     * @param array<string,string> $prefill
     */
    public function __construct(
        Phrase $phrase,
        private readonly array $requiredFields = [],
        private readonly array $prefill = [],
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * @return array<string,bool>
     */
    public function getRequiredFields(): array
    {
        return $this->requiredFields;
    }

    /**
     * @return array<string,string>
     */
    public function getPrefill(): array
    {
        return $this->prefill;
    }
}
