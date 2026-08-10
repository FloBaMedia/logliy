<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Counter;

use Logliy\Webauthn\CredentialRecord;

interface CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void;
}
