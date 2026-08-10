<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Util;

use Logliy\ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Logliy\Webauthn\Exception\InvalidDataException;

abstract class Base64
{
    public static function decode(string $data): string
    {
        try {
            return Base64UrlSafe::decode($data);
        } catch (Throwable) {
        }

        try {
            return \Logliy\ParagonIE\ConstantTime\Base64::decode($data);
        } catch (Throwable $e) {
            throw InvalidDataException::create($data, 'Invalid data submitted', $e);
        }
    }
}
