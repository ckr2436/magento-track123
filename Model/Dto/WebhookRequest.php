<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Dto;

class WebhookRequest
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $jsonBody
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly ?array $jsonBody = null,
        public readonly ?int $storeId = null
    ) {
    }
}
