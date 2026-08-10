<?php

declare(strict_types=1);

namespace Logliy\Webauthn\CeremonyStep;

use Logliy\CBOR\Decoder;
use Logliy\CBOR\Normalizable;
use Logliy\Cose\Algorithm\Manager;
use Logliy\Cose\Algorithm\Signature\ECDSA\ES256;
use Logliy\Cose\Algorithm\Signature\RSA\RS256;
use Logliy\Cose\Algorithm\Signature\Signature;
use Logliy\Cose\Key\Key;
use function is_array;
use function trigger_deprecation;
use Logliy\Webauthn\AuthenticatorAssertionResponse;
use Logliy\Webauthn\AuthenticatorAttestationResponse;
use Logliy\Webauthn\CredentialRecord;
use Logliy\Webauthn\Exception\AuthenticatorResponseVerificationException;
use Logliy\Webauthn\PublicKeyCredentialCreationOptions;
use Logliy\Webauthn\PublicKeyCredentialRequestOptions;
use Logliy\Webauthn\PublicKeyCredentialSource;
use Logliy\Webauthn\StringStream;
use Logliy\Webauthn\U2FPublicKey;
use Logliy\Webauthn\Util\CoseSignatureFixer;

final readonly class CheckSignature implements CeremonyStep
{
    private Manager $algorithmManager;

    public function __construct(null|Manager $algorithmManager = null)
    {
        $this->algorithmManager = $algorithmManager ?? Manager::create()->add(ES256::create(), RS256::create());
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
        if (! $authenticatorResponse instanceof AuthenticatorAssertionResponse) {
            return;
        }
        $credentialPublicKey = $credentialRecord->getAttestedCredentialData()
            ->credentialPublicKey;
        $credentialPublicKey !== null || throw AuthenticatorResponseVerificationException::create(
            'No public key available.'
        );
        $coseKey = $this->getCoseKey($credentialPublicKey);

        $getClientDataJSONHash = hash('sha256', $authenticatorResponse->clientDataJSON->rawData, true);
        $dataToVerify = $authenticatorResponse->authenticatorData->authData . $getClientDataJSONHash;
        $signature = $authenticatorResponse->signature;
        $algorithm = $this->algorithmManager->get($coseKey->alg());
        $algorithm instanceof Signature || throw AuthenticatorResponseVerificationException::create(
            'Invalid algorithm identifier. Should refer to a signature algorithm'
        );
        $signature = CoseSignatureFixer::fix($signature, $algorithm);
        $algorithm->verify(
            $dataToVerify,
            $coseKey,
            $signature
        ) || throw AuthenticatorResponseVerificationException::create('Invalid signature.');
    }

    private function getCoseKey(string $credentialPublicKey): Key
    {
        $isU2F = U2FPublicKey::isU2FKey($credentialPublicKey);
        if ($isU2F === true) {
            $credentialPublicKey = U2FPublicKey::convertToCoseKey($credentialPublicKey);
        }
        $stream = new StringStream($credentialPublicKey);
        $credentialPublicKeyStream = Decoder::create()->decode($stream);
        $stream->isEOF() || throw AuthenticatorResponseVerificationException::create(
            'Invalid key. Presence of extra bytes.'
        );
        $stream->close();
        $credentialPublicKeyStream instanceof Normalizable || throw AuthenticatorResponseVerificationException::create(
            'Invalid attestation object. Unexpected object.'
        );
        $normalizedData = $credentialPublicKeyStream->normalize();
        is_array($normalizedData) || throw AuthenticatorResponseVerificationException::create(
            'Invalid attestation object. Unexpected object.'
        );
        /** @var array<int|string, mixed> $normalizedData */

        return Key::create($normalizedData);
    }
}
