<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Signal;

use Logliy\Webauthn\PublicKeyCredentialRpEntity;
use Logliy\Webauthn\PublicKeyCredentialUserEntity;

readonly class CurrentUserDetails implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialUserEntity $user,
    ) {
    }
}
