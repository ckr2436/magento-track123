<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Provider\Track123;

use Pynarae\Tracking\Model\Track123Client;

class Track123HttpClient
{
    public function __construct(private readonly Track123Client $client)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verification
     * @return array<string,mixed>
     */
    public function register(array $payload, ?int $storeId = null, array $verification = []): array
    {
        return $this->client->registerTrackings($payload, $storeId, $verification);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{postal_code?:string,phone_suffix?:string} $verification
     * @return array<string,mixed>
     */
    public function query(array $payload, ?int $storeId = null, array $verification = []): array
    {
        return $this->client->queryTrackings($payload, $storeId, $verification);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function detectCourier(array $payload, ?int $storeId = null): array
    {
        return $this->client->detectCourier($payload, $storeId);
    }
}
