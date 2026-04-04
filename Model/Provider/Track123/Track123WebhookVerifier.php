<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Pynarae\Tracking\Model\Dto\WebhookRequest;
use Pynarae\Tracking\Model\WebhookSignatureVerifier;

class Track123WebhookVerifier
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier)
    {
    }

    public function verify(WebhookRequest $request): bool
    {
        $timestamp = (string)($request->headers['x-track123-timestamp'] ?? '');
        $signature = (string)($request->headers['x-track123-signature'] ?? '');
        return $this->verifier->verify($request->rawBody, $timestamp, $signature, $request->storeId);
    }
}
