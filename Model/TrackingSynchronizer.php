<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;

class TrackingSynchronizer
{
    public function __construct(
        private readonly Track123Client $track123Client,
        private readonly CourierCodeResolver $courierCodeResolver,
        private readonly TrackingCacheManager $trackingCacheManager,
        private readonly Track123PayloadExtractor $payloadExtractor,
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
        $storeId = (int)$track->getStoreId();
        $trackingNumber = trim((string)$track->getTrackNumber());
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Shipment track has no tracking number.'));
        }

        $shipment = $track->getShipment();
        $order = $shipment->getOrder();

        $courierCode = $this->courierCodeResolver->resolve($track, $storeId);
        if ($courierCode === null && $this->config->shouldAutoDetectCarrier($storeId)) {
            $courierCode = $this->detectCourierCode($trackingNumber, (int)$track->getId(), $storeId);
        }

        $payload = [
            [
                'trackNo' => $trackingNumber,
                'orderNo' => (string)$order->getIncrementId(),
            ],
        ];

        if ($courierCode) {
            $payload[0]['courierCode'] = $courierCode;
        }

        $this->executeWithAdaptiveVerification(
            $order,
            $manualVerification,
            fn(array $context) => $this->track123Client->registerTrackings($payload, $storeId, $context)
        );

        $this->trackingCacheManager->markRegistered((int)$track->getId(), true);
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @return array<string, mixed>|null
     * @throws LocalizedException
     */
    public function queryTrack(Track $track, array $manualVerification = []): ?array
    {
        $storeId = (int)$track->getStoreId();
        $trackingNumber = trim((string)$track->getTrackNumber());
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Shipment track has no tracking number.'));
        }

        $shipment = $track->getShipment();
        $order = $shipment->getOrder();

        $response = $this->executeWithAdaptiveVerification(
            $order,
            $manualVerification,
            fn(array $context) => $this->track123Client->queryTrackings(
                ['trackNos' => [$trackingNumber]],
                $storeId,
                $context
            )
        );

        $items = $this->payloadExtractor->extractTrackingItems($response);
        $item = $items[0] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $this->trackingCacheManager->upsertFromTrack123Payload($item, [
            'track_id' => (int)$track->getId(),
            'store_id' => (int)$track->getStoreId(),
            'order_id' => (int)$shipment->getOrderId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'shipment_id' => (int)$track->getParentId(),
            'tracking_number' => $trackingNumber,
        ]);

        return $item;
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string} $manualVerification
     * @param callable(array{postal_code?:string,phone_suffix?:string}):array<string,mixed> $operation
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function executeWithAdaptiveVerification(Order $order, array $manualVerification, callable $operation): array
    {
        $manualContext = $this->verificationContextResolver->forOrder($order, $manualVerification);
        if ($manualContext !== []) {
            try {
                return $operation($manualContext);
            } catch (AdditionalVerificationRequiredException $e) {
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
        } catch (AdditionalVerificationRequiredException $e) {
            $autoContext = $this->verificationContextResolver->forOrder($order, []);
            $attempts = $this->buildAdaptiveAttempts($e->getRequiredFields(), $autoContext);

            foreach ($attempts as $context) {
                try {
                    return $operation($context);
                } catch (AdditionalVerificationRequiredException $next) {
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
            // 返回不明确：先 postalCode，再 phoneSuffix，最后两者一起
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


    private function detectCourierCode(string $trackingNumber, int $trackId, ?int $storeId = null): ?string
    {
        $attemptPayloads = [
            ['tracking_number' => $trackingNumber],
            ['trackNo' => $trackingNumber],
        ];

        $attemptedPayloadKeys = [];
        $lastException = null;

        foreach ($attemptPayloads as $payload) {
            $attemptedPayloadKeys[] = array_keys($payload);

            try {
                $detect = $this->track123Client->detectCourier($payload, $storeId);
                $code = $this->extractDetectedCode($detect);
                if ($code !== null) {
                    return $code;
                }
            } catch (LocalizedException $e) {
                $lastException = $e;
                if (!$this->isPayloadFormatRetryableError($e->getMessage())) {
                    break;
                }
            }
        }

        if ($lastException !== null) {
            $this->logger->warning('Track123 carrier detection failed', [
                'track_id' => $trackId,
                'attempted_payload_keys' => $attemptedPayloadKeys,
                'exception' => $lastException,
            ]);
        }

        return null;
    }

    private function isPayloadFormatRetryableError(string $message): bool
    {
        $message = mb_strtolower($message);
        $payloadErrorKeywords = [
            'trackno',
            'tracking_number',
            'tracking number',
            'invalid param',
            'invalid parameter',
            'missing param',
            'missing parameter',
            'required field',
            'required parameter',
        ];

        foreach ($payloadErrorKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractDetectedCode(array $response): ?string
    {
        foreach (['data', 'result'] as $key) {
            $candidate = $response[$key] ?? null;
            if (is_array($candidate)) {
                if (isset($candidate['code']) && is_string($candidate['code'])) {
                    return $candidate['code'];
                }
                if (array_is_list($candidate) && isset($candidate[0]['code']) && is_string($candidate[0]['code'])) {
                    return $candidate[0]['code'];
                }
                foreach (['items', 'list'] as $nested) {
                    if (isset($candidate[$nested][0]['code']) && is_string($candidate[$nested][0]['code'])) {
                        return $candidate[$nested][0]['code'];
                    }
                }
            }
        }

        return null;
    }
}
