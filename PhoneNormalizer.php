<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Model;

class PhoneNormalizer
{
    public function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    public function matches(string $input, ?string $candidate): bool
    {
        $left = $this->normalize($input);
        $right = $this->normalize((string)$candidate);

        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || str_ends_with($right, $left) || str_ends_with($left, $right);
    }

    public function suffix(string $value, int $length = 4): string
    {
        $digits = $this->normalize($value);
        if ($digits === '') {
            return '';
        }

        return mb_substr($digits, -1 * max(1, $length));
    }
}
