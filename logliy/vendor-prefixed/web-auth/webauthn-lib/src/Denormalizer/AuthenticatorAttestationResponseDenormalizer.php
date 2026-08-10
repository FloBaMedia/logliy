<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Denormalizer;

use Logliy\ParagonIE\ConstantTime\Base64UrlSafe;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Logliy\Webauthn\AttestationStatement\AttestationObject;
use Logliy\Webauthn\AuthenticatorAttestationResponse;
use Logliy\Webauthn\CollectedClientData;
use Logliy\Webauthn\Exception\InvalidDataException;
use Logliy\Webauthn\Util\Base64;

final class AuthenticatorAttestationResponseDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{clientDataJSON: string, attestationObject: string, transports?: string[]} $data */
        $data['clientDataJSON'] = Base64UrlSafe::decodeNoPadding($data['clientDataJSON']);
        $data['attestationObject'] = Base64::decode($data['attestationObject']);

        /** @var CollectedClientData $clientDataJSON */
        $clientDataJSON = $this->denormalizer->denormalize(
            $data['clientDataJSON'],
            CollectedClientData::class,
            $format,
            $context
        );
        /** @var AttestationObject $attestationObject */
        $attestationObject = $this->denormalizer->denormalize(
            $data['attestationObject'],
            AttestationObject::class,
            $format,
            $context
        );

        return AuthenticatorAttestationResponse::create(
            $clientDataJSON,
            $attestationObject,
            $data['transports'] ?? [],
        );
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AuthenticatorAttestationResponse::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AuthenticatorAttestationResponse::class => true,
        ];
    }
}
