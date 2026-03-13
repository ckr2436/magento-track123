<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

use Magento\Sales\Model\Order;

class VerificationContextResolver
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer
    ) {
    }

    /**
     * @param array{postal_code?:string,phone_suffix?:string,verification_postal_code?:string,verification_phone_suffix?:string} $manualVerification
     * @return array{postal_code?:string,phone_suffix?:string}
     */
    public function forOrder(Order $order, array $manualVerification = []): array
    {
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();

        $autoPostal = $this->normalizePostalCode(
            (string)($shipping?->getPostcode() ?: $billing?->getPostcode() ?: '')
        );

        $autoPhoneSuffix = $this->phoneNormalizer->suffix(
            (string)($shipping?->getTelephone() ?: $billing?->getTelephone() ?: '')
        );

        $manualPostal = $this->normalizePostalCode(
            (string)($manualVerification['postal_code'] ?? $manualVerification['verification_postal_code'] ?? '')
        );

        $manualPhoneSuffix = $this->phoneNormalizer->suffix(
            (string)($manualVerification['phone_suffix'] ?? $manualVerification['verification_phone_suffix'] ?? '')
        );

        $postalCode = $manualPostal !== '' ? $manualPostal : $autoPostal;
        $phoneSuffix = $manualPhoneSuffix !== '' ? $manualPhoneSuffix : $autoPhoneSuffix;

        $result = [];
        if ($postalCode !== '') {
            $result['postal_code'] = $postalCode;
        }
        if ($phoneSuffix !== '') {
            $result['phone_suffix'] = $phoneSuffix;
        }

        return $result;
    }

    private function normalizePostalCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9\-\s]/', '', $value) ?: '';
        $value = trim($value);

        return mb_substr($value, 0, 15);
    }
}
