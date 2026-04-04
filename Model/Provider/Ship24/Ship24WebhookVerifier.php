<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Ship24;

use Pynarae\Tracking\Model\Config;
use Pynarae\Tracking\Model\Dto\WebhookRequest;

class Ship24WebhookVerifier
{
    public function __construct(private readonly Config $config)
    {
    }

    public function verify(WebhookRequest $request): bool
    {
        if (!$this->config->isStrictSignatureValidation($request->storeId)) {
            return true;
        }

        $expected = trim($this->config->getShip24WebhookSecret($request->storeId));
        $received = trim((string)($request->headers['authorization'] ?? ''));

        if ($expected === '' || $received === '') {
            return false;
        }

        return hash_equals('Bearer ' . $expected, $received);
    }
}
