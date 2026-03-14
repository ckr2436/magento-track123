<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Rate\ResultFactory as RateResultFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result;
use Magento\Shipping\Model\Tracking\Result\Error;
use Magento\Shipping\Model\Tracking\Result\ErrorFactory as TrackingErrorFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Shipping\Model\Tracking\ResultFactory;
use Psr\Log\LoggerInterface;
use Pynarae\Tracking\Model\StoreTrackingLocator;
use Pynarae\Tracking\Model\TrackingCacheManager;
use Pynarae\Tracking\Model\TrackingSynchronizer;

class Track123 extends AbstractCarrierOnline
{
    protected $_code = 'track123_custom';

    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $trackFactory,
        TrackingErrorFactory $trackErrorFactory,
        StatusFactory $trackStatusFactory,
        Security $xmlSecurity,
        ElementFactory $xmlElementFactory,
        RateResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        RegionFactory $regionFactory,
        CountryFactory $countryFactory,
        CurrencyFactory $currencyFactory,
        StockRegistryInterface $stockRegistry,
        private readonly StoreTrackingLocator $storeTrackingLocator,
        private readonly TrackingSynchronizer $trackingSynchronizer,
        private readonly TrackingCacheManager $trackingCacheManager,
        array $data = []
    ) {
        $this->_trackFactory = $trackFactory;
        $this->_trackErrorFactory = $trackErrorFactory;
        $this->_trackStatusFactory = $trackStatusFactory;
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElementFactory,
            $rateResultFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $stockRegistry,
            $data
        );
    }

    public function collectRates(RateRequest $request)
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => (string)__('Track123')];
    }

    public function isTrackingAvailable(): bool
    {
        return true;
    }

    /**
     * @param string|array<int, string> $trackingNumber
     */
    public function getTracking($trackingNumber): Result|Error
    {
        $tracking = $this->normalizeTrackingNumber($trackingNumber);
        if ($tracking === '') {
            return $this->buildErrorResult($tracking, (string)__('Tracking number is required.'));
        }

        try {
            $track = $this->resolveOwnedTrack($tracking);
            $cache = $this->trackingCacheManager->getByTrackId((int)$track->getId());

            if ($cache === null || !(bool)$cache->getData('is_registered')) {
                $this->trackingSynchronizer->registerTrack($track);
            }

            $this->trackingSynchronizer->queryTrack($track);
            $cache = $this->trackingCacheManager->getByTrackId((int)$track->getId());

            $result = $this->_trackFactory->create();
            $status = $this->_trackStatusFactory->create();

            $title = (string)($cache?->getData('carrier_name') ?: $this->getConfigData('title') ?: 'Track123');
            $statusLabel = (string)($cache?->getData('transit_status') ?: __('In Transit'));
            $summary = (string)($cache?->getData('transit_sub_status') ?: '');
            $externalUrl = (string)($cache?->getData('external_query_url') ?: '');
            $deliveryDateTime = (string)($cache?->getData('delivery_date') ?: '');

            $status->setCarrier($this->_code)
                ->setCarrierTitle($title)
                ->setTracking($tracking)
                ->setStatus($statusLabel)
                ->setTrackSummary($summary)
                ->setUrl($externalUrl)
                ->setDeliverydate($this->extractDatePart($deliveryDateTime))
                ->setDeliverytime($this->extractTimePart($deliveryDateTime))
                ->setProgressdetail($this->buildProgressDetail($cache?->getData('events_json')));

            $result->append($status);

            return $result;
        } catch (\Throwable $e) {
            $this->_logger->warning('Track123 popup tracking query failed', [
                'tracking_number' => $tracking,
                'exception' => $e,
            ]);

            return $this->buildErrorResult(
                $tracking,
                (string)__('Tracking information is currently unavailable. Please try again later.')
            );
        }
    }

    protected function _doShipmentRequest(DataObject $request)
    {
        throw new LocalizedException(__('Shipment label creation is not supported.'));
    }

    /**
     * @param string|array<int, string> $trackingNumber
     */
    private function normalizeTrackingNumber(string|array $trackingNumber): string
    {
        if (is_array($trackingNumber)) {
            $trackingNumber = (string)reset($trackingNumber);
        }

        return trim((string)$trackingNumber);
    }

    private function resolveOwnedTrack(string $trackingNumber): Track
    {
        $tracks = $this->storeTrackingLocator->locate($trackingNumber);
        if ($tracks === []) {
            throw new LocalizedException(__('Tracking number is not associated with this store.'));
        }

        return $tracks[0];
    }

    /**
     * @param mixed $eventsJson
     * @return array<int, array{deliverydate:string,deliverytime:string,deliverylocation:string,activity:string}>
     */
    private function buildProgressDetail(mixed $eventsJson): array
    {
        if (!is_string($eventsJson) || $eventsJson === '') {
            return [];
        }

        $events = json_decode($eventsJson, true);
        if (!is_array($events)) {
            return [];
        }

        $progress = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $time = (string)($event['time'] ?? '');
            $progress[] = [
                'deliverydate' => $this->extractDatePart($time),
                'deliverytime' => $this->extractTimePart($time),
                'deliverylocation' => (string)($event['location'] ?? ''),
                'activity' => (string)($event['detail'] ?? ''),
            ];
        }

        return $progress;
    }

    private function extractDatePart(string $dateTime): string
    {
        if (trim($dateTime) === '') {
            return '';
        }

        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }

    private function extractTimePart(string $dateTime): string
    {
        if (trim($dateTime) === '') {
            return '';
        }

        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('H:i', $timestamp);
    }

    private function buildErrorResult(string $trackingNumber, string $errorMessage): Error
    {
        /** @var Error $error */
        $error = $this->_trackErrorFactory->create();
        $error->setCarrier($this->_code);
        $error->setCarrierTitle((string)($this->getConfigData('title') ?: 'Track123'));
        $error->setTracking($trackingNumber);
        $error->setErrorMessage($errorMessage);

        return $error;
    }
}
