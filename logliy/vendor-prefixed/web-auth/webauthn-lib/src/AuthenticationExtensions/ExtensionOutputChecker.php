<?php

declare(strict_types=1);

namespace Logliy\Webauthn\AuthenticationExtensions;

interface ExtensionOutputChecker
{
    public function check(AuthenticationExtensions $inputs, AuthenticationExtensions $outputs): void;
}
