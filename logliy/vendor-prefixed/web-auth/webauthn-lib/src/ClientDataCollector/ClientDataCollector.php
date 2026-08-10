<?php

declare(strict_types=1);

namespace Logliy\Webauthn\ClientDataCollector;

use Logliy\Webauthn\AuthenticatorResponse;
use Logliy\Webauthn\CollectedClientData;
use Logliy\Webauthn\PublicKeyCredentialOptions;

interface ClientDataCollector
{
    /**
     * @return string[]
     */
    public function supportedTypes(): array;

    public function verifyCollectedClientData(
        CollectedClientData $collectedClientData,
        PublicKeyCredentialOptions $publicKeyCredentialOptions,
        AuthenticatorResponse $authenticatorResponse,
        string $host
    ): void;
}
