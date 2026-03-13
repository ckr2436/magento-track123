<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Controller\Lookup;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\View\Result\PageFactory;
use Pynarae\Tracking\Model\Exception\AdditionalVerificationRequiredException;
use Pynarae\Tracking\Model\LookupService;

class Index extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly LookupService $lookupService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $queryMode = (string)$this->getRequest()->getParam('query_mode');
        if ($queryMode === '') {
            $queryMode = (string)$this->getRequest()->getParam('lookup_mode', 'order');
        }

        if (!in_array($queryMode, ['order', 'tracking'], true)) {
            $queryMode = 'order';
        }

        $incrementId = trim((string)$this->getRequest()->getParam('increment_id'));
        if ($incrementId === '') {
            $incrementId = trim((string)$this->getRequest()->getParam('order_number'));
        }

        $emailOrPhone = trim((string)$this->getRequest()->getParam('email_or_phone'));
        $trackingNumber = trim((string)$this->getRequest()->getParam('tracking_number'));

        $verificationPostalCode = trim((string)$this->getRequest()->getParam('verification_postal_code'));
        $verificationPhoneSuffix = trim((string)$this->getRequest()->getParam('verification_phone_suffix'));

        $manualVerification = [
            'postal_code' => $verificationPostalCode,
            'phone_suffix' => $verificationPhoneSuffix,
        ];

        $formValues = [
            'query_mode' => $queryMode,
            'increment_id' => $incrementId,
            'email_or_phone' => $emailOrPhone,
            'tracking_number' => $trackingNumber,
            'verification_postal_code' => $verificationPostalCode,
            'verification_phone_suffix' => $verificationPhoneSuffix,
        ];

        $lookupError = null;
        $lookupResult = null;
        $lookupChallenge = null;

        if (!$this->formKeyValidator->validate($this->getRequest())) {
            $lookupError = (string)__('Your session expired. Please refresh the page and try again.');
        } else {
            try {
                $lookupResult = $queryMode === 'tracking'
                    ? $this->lookupService->lookupByTrackingNumber($trackingNumber, $manualVerification)
                    : $this->lookupService->lookupByOrder($incrementId, $emailOrPhone, $manualVerification);
            } catch (AdditionalVerificationRequiredException $e) {
                $requiresPostal = $e->requiresPostalCode();
                $requiresPhone = $e->requiresPhoneSuffix();

                // 如果返回不明确，按你的要求：先让用户补 postalCode
                if (!$requiresPostal && !$requiresPhone) {
                    $requiresPostal = true;
                }

                $lookupChallenge = [
                    'requires_postal_code' => $requiresPostal,
                    'requires_phone_suffix' => $requiresPhone,
                    'prefill' => $e->getPrefill(),
                    'title' => (string)__('Additional verification required'),
                    'message' => (string)__('This carrier requires extra destination verification before tracking updates can be shown. We already tried the order details stored in Magento. Please enter the missing information below.'),
                ];
            } catch (\Throwable $e) {
                $lookupError = (string)$e->getMessage();
            }
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Track Your Order'));

        $layout = $page->getLayout();

        if ($block = $layout->getBlock('pynarae.tracking.form')) {
            $block->setData('form_values', $formValues);
            $block->setData('lookup_error', $lookupError);
            $block->setData('lookup_result', $lookupResult);
            $block->setData('lookup_challenge', $lookupChallenge);
        }

        if ($block = $layout->getBlock('pynarae.tracking.result')) {
            $block->setData('lookup_result', $lookupResult);
            $block->setData('lookup_error', $lookupError);
            $block->setData('form_values', $formValues);
            $block->setData('lookup_challenge', $lookupChallenge);
        }

        return $page;
    }
}
