<?php

declare(strict_types=1);

namespace Logliy\Webauthn\AuthenticationExtensions;

final class AppIdExcludeInputExtension extends AuthenticationExtension
{
    public static function enable(string $value): AuthenticationExtension
    {
        return self::create('appidExclude', $value);
    }
}
