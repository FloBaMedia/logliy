<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Signal;

use Logliy\Webauthn\PublicKeyCredentialDescriptor;
use Logliy\Webauthn\PublicKeyCredentialRpEntity;

readonly class UnknownCredential implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialDescriptor $credential,
    ) {
    }
}
