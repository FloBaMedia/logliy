<?php

declare(strict_types=1);

namespace Logliy\Webauthn\CeremonyStep;

use function trigger_deprecation;
use Logliy\Webauthn\AuthenticatorAssertionResponse;
use Logliy\Webauthn\AuthenticatorAttestationResponse;
use Logliy\Webauthn\ClientDataCollector\ClientDataCollectorManager;
use Logliy\Webauthn\ClientDataCollector\WebauthnAuthenticationCollector;
use Logliy\Webauthn\CredentialRecord;
use Logliy\Webauthn\PublicKeyCredentialCreationOptions;
use Logliy\Webauthn\PublicKeyCredentialRequestOptions;
use Logliy\Webauthn\PublicKeyCredentialSource;

final readonly class CheckClientDataCollectorType implements CeremonyStep
{
    private ClientDataCollectorManager $clientDataCollectorManager;

    public function __construct(null|ClientDataCollectorManager $clientDataCollectorManager = null)
    {
        $this->clientDataCollectorManager = $clientDataCollectorManager ?? new ClientDataCollectorManager([
            new WebauthnAuthenticationCollector(),
        ]);
    }

    public function process(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse|AuthenticatorAttestationResponse $authenticatorResponse,
        PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
        ?string $userHandle,
        string $host
    ): void {
        if ($credentialRecord instanceof PublicKeyCredentialSource) {
            logliy_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::process()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }
        $this->clientDataCollectorManager->collect(
            $authenticatorResponse->clientDataJSON,
            $publicKeyCredentialOptions,
            $authenticatorResponse,
            $host
        );
    }
}
