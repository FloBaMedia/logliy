<?php

declare(strict_types=1);

namespace Logliy\Webauthn\CeremonyStep;

use Logliy\Webauthn\Exception\AuthenticatorResponseVerificationException;

final readonly class HostTopOriginValidator implements TopOriginValidator
{
    public function __construct(
        private string $host
    ) {
    }

    public function validate(string $topOrigin): void
    {
        $topOrigin === $this->host || throw AuthenticatorResponseVerificationException::create(
            'The top origin does not correspond to the host.'
        );
    }
}
