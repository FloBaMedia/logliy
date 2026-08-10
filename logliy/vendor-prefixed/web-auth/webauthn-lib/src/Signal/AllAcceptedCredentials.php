<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Signal;

use Logliy\Webauthn\PublicKeyCredentialDescriptor;
use Logliy\Webauthn\PublicKeyCredentialRpEntity;
use Logliy\Webauthn\PublicKeyCredentialUserEntity;

readonly class AllAcceptedCredentials implements Signal
{
    /**
     * @param PublicKeyCredentialDescriptor[] $allAcceptedCredentials
     */
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialUserEntity $user,
        public array $allAcceptedCredentials,
    ) {
    }
}
