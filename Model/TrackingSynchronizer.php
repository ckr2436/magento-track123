<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Exception\ProviderActionRequiredException;
use Pynarae\Tracking\Model\Dto\ProviderContext;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;
use Pynarae\Tracking\Model\Provider\ProviderResolver;

class TrackingSynchronizer
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly VerificationContextResolver $verificationContextResolver,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @throws LocalizedException
     */
    public function registerTrack(Track $track, array $manualVerification = []): void
    {
        $trackingNumber = trim((string)$track->getTrackNumber());
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Shipment track has no tracking number.'));
        }

        $shipment = $track->getShipment();
        $order = $shipment->getOrder();
        $providerCode = $this->resolveProviderCodeForTrack($track);

        $this->executeWithAdaptiveVerification(
            $order,
            $manualVerification,
            function (array $verificationContext) use ($track, $order, $providerCode) {
                $provider = $this->providerResolver->resolveByCode($providerCode);
                $context = $this->buildProviderContext($track, $order, $providerCode, $verificationContext);
                $provider->register($context);
            }
        );

        $this->trackingCacheManager->markRegistered((int)$track->getId(), true);
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @return array<string,mixed>|null
     * @throws LocalizedException
     */
    public function queryTrack(Track $track, array $manualVerification = []): ?array
    {
        $trackingNumber = trim((string)$track->getTrackNumber());
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Shipment track has no tracking number.'));
        }

        $shipment = $track->getShipment();
        $order = $shipment->getOrder();
        $providerCode = $this->resolveProviderCodeForTrack($track);

        $result = $this->executeWithAdaptiveVerification(
            $order,
            $manualVerification,
            function (array $verificationContext) use ($track, $order, $providerCode) {
                $provider = $this->providerResolver->resolveByCode($providerCode);
                $context = $this->buildProviderContext($track, $order, $providerCode, $verificationContext);
                return $provider->query($context);
            }
        );

        if ($result === null) {
            return null;
        }

        $this->trackingCacheManager->upsertFromProviderResult($result, [
            'track_id' => (int)$track->getId(),
            'store_id' => (int)$track->getStoreId(),
            'order_id' => (int)$shipment->getOrderId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'shipment_id' => (int)$track->getParentId(),
            'tracking_number' => $trackingNumber,
        ]);

        return $result->rawPayload;
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @param callable(array{postal_code?:string,phone_suffix?:string}):mixed $operation
     * @return mixed
     * @throws LocalizedException
     */
    private function executeWithAdaptiveVerification(Order $order, array $manualVerification, callable $operation): mixed
    {
        $manualContext = $this->verificationContextResolver->forOrder($order, $manualVerification);
        if ($manualContext !== []) {
            try {
                return $operation($manualContext);
            } catch (ProviderActionRequiredException $e) {
                $required = $this->normalizeChallengeForUi($e->getRequiredFields());
                throw new AdditionalVerificationRequiredException(
                    __('Additional shipment verification is still required. Please verify the requested fields and try again.'),
                    $required,
                    $manualContext
                );
            }
        }

        try {
            return $operation([]);
        } catch (ProviderActionRequiredException $e) {
            $autoContext = $this->verificationContextResolver->forOrder($order, []);
            $attempts = $this->buildAdaptiveAttempts($e->getRequiredFields(), $autoContext);

            foreach ($attempts as $context) {
                try {
                    return $operation($context);
                } catch (ProviderActionRequiredException $next) {
                    $e = $next;
                    continue;
                }
            }

            $required = $this->normalizeChallengeForUi($e->getRequiredFields());
            if ($required === []) {
                $required = ['postal_code' => true];
            }

            throw new AdditionalVerificationRequiredException(
                __('Additional shipment verification is required before tracking can be retrieved.'),
                $required,
                $autoContext
            );
        }
    }

    /**
     * @param array{postal_code?:bool,phone_suffix?:bool} $requiredFields
     * @param array{postal_code?:string,phone_suffix?:string} $autoContext
     * @return array<int, array{postal_code?:string,phone_suffix?:string}>
     */
    private function buildAdaptiveAttempts(array $requiredFields, array $autoContext): array
    {
        $hasPostal = !empty($autoContext['postal_code']);
        $hasPhone = !empty($autoContext['phone_suffix']);

        $explicitPostal = (bool)($requiredFields['postal_code'] ?? false);
        $explicitPhone = (bool)($requiredFields['phone_suffix'] ?? false);

        $attempts = [];

        if ($explicitPostal && $explicitPhone) {
            if ($hasPostal || $hasPhone) {
                $ctx = [];
                if ($hasPostal) {
                    $ctx['postal_code'] = $autoContext['postal_code'];
                }
                if ($hasPhone) {
                    $ctx['phone_suffix'] = $autoContext['phone_suffix'];
                }
                if ($ctx !== []) {
                    $attempts[] = $ctx;
                }
            }
        } elseif ($explicitPostal) {
            if ($hasPostal) {
                $attempts[] = ['postal_code' => $autoContext['postal_code']];
            }
        } elseif ($explicitPhone) {
            if ($hasPhone) {
                $attempts[] = ['phone_suffix' => $autoContext['phone_suffix']];
            }
        } else {
            if ($hasPostal) {
                $attempts[] = ['postal_code' => $autoContext['postal_code']];
            }
            if ($hasPhone) {
                $attempts[] = ['phone_suffix' => $autoContext['phone_suffix']];
            }
            if ($hasPostal || $hasPhone) {
                $ctx = [];
                if ($hasPostal) {
                    $ctx['postal_code'] = $autoContext['postal_code'];
                }
                if ($hasPhone) {
                    $ctx['phone_suffix'] = $autoContext['phone_suffix'];
                }
                if ($ctx !== []) {
                    $attempts[] = $ctx;
                }
            }
        }

        $unique = [];
        $seen = [];
        foreach ($attempts as $attempt) {
            ksort($attempt);
            $hash = md5((string)json_encode($attempt));
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $attempt;
            }
        }

        return $unique;
    }

    /**
     * @param array{postal_code?:bool,phone_suffix?:bool} $requiredFields
     * @return array{postal_code?:bool,phone_suffix?:bool}
     */
    private function normalizeChallengeForUi(array $requiredFields): array
    {
        $normalized = [];
        if (!empty($requiredFields['postal_code'])) {
            $normalized['postal_code'] = true;
        }
        if (!empty($requiredFields['phone_suffix'])) {
            $normalized['phone_suffix'] = true;
        }
        return $normalized;
    }

    private function resolveProviderCodeForTrack(Track $track): string
    {
        $cache = $this->trackingCacheManager->getByTrackId((int)$track->getId());
        $cachedProvider = trim((string)($cache?->getData('provider_code') ?? ''));
        if ($cachedProvider !== '') {
            return $cachedProvider;
        }

        return $this->config->getDefaultProvider((int)$track->getStoreId());
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $verification
     */
    private function buildProviderContext(Track $track, Order $order, string $providerCode, array $verification): ProviderContext
    {
        return new ProviderContext(
            providerCode: $providerCode,
            storeId: (int)$track->getStoreId(),
            orderId: (int)$order->getId(),
            shipmentId: (int)$track->getParentId(),
            trackId: (int)$track->getId(),
            orderIncrementId: (string)$order->getIncrementId(),
            trackingNumber: (string)$track->getTrackNumber(),
            carrierCode: (string)$track->getCarrierCode(),
            carrierTitle: (string)$track->getTitle(),
            verification: $verification
        );
    }
}
