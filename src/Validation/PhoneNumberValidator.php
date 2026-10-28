<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Validation;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class PhoneNumberValidator
{
    private readonly PhoneNumberUtil $util;

    public function __construct(private readonly string $defaultRegion)
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    public function normalise(string $number): string
    {
        $number = trim($number);

        try {
            $phone = $this->util->parse($number, $this->defaultRegion);
        } catch (NumberParseException $e) {
            throw new \InvalidArgumentException("Invalid phone number: {$number}", 0, $e);
        }

        if (!$this->util->isValidNumber($phone)) {
            throw new \InvalidArgumentException("Invalid phone number: {$number}");
        }

        return $this->util->format($phone, PhoneNumberFormat::E164);
    }

    public function isValid(string $number): bool
    {
        try {
            $this->normalise($number);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
