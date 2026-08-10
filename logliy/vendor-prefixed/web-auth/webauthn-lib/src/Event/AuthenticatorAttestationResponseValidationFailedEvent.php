<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Event;

use Throwable;
use Logliy\Webauthn\AuthenticatorAttestationResponse;
use Logliy\Webauthn\PublicKeyCredentialCreationOptions;

readonly class AuthenticatorAttestationResponseValidationFailedEvent
{
    public function __construct(
        public AuthenticatorAttestationResponse $authenticatorAttestationResponse,
        public PublicKeyCredentialCreationOptions $publicKeyCredentialCreationOptions,
        public string $host,
        public Throwable $throwable
    ) {
    }
}
