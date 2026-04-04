<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Api\Provider;

use Pynarae\Tracking\Model\Dto\ProviderContext;
use Pynarae\Tracking\Model\Dto\ProviderTrackingResult;
use Pynarae\Tracking\Model\Dto\WebhookRequest;

interface TrackingProviderInterface
{
    public function getCode(): string;

    public function isEnabled(?int $storeId = null): bool;

    public function supportsRegistration(): bool;

    public function supportsWebhook(): bool;

    public function register(ProviderContext $context): ?ProviderTrackingResult;

    public function query(ProviderContext $context): ?ProviderTrackingResult;

    public function verifyWebhook(WebhookRequest $request): bool;

    /**
     * @return ProviderTrackingResult[]
     */
    public function parseWebhook(WebhookRequest $request): array;
}
