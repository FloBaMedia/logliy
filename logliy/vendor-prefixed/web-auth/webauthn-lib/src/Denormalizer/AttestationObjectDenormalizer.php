<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Denormalizer;

use Logliy\CBOR\Decoder;
use Logliy\CBOR\Normalizable;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Logliy\Webauthn\AttestationStatement\AttestationObject;
use Logliy\Webauthn\AttestationStatement\AttestationStatement;
use Logliy\Webauthn\AuthenticatorData;
use Logliy\Webauthn\Exception\InvalidDataException;
use Logliy\Webauthn\StringStream;

final class AttestationObjectDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var string $data */
        $stream = new StringStream($data);
        $parsed = Decoder::create()->decode($stream);

        $parsed instanceof Normalizable || throw InvalidDataException::create(
            $parsed,
            'Invalid attestation object. Unexpected object.'
        );
        /** @var array<string, mixed> $attestationObject */
        $attestationObject = $parsed->normalize();
        $stream->isEOF() || throw InvalidDataException::create(
            null,
            'Invalid attestation object. Presence of extra bytes.'
        );
        $stream->close();
        $authData = $attestationObject['authData'] ?? throw InvalidDataException::create(
            $attestationObject,
            'Invalid attestation object. Missing "authData" field.'
        );

        return AttestationObject::create(
            $data,
            $this->denormalizer->denormalize($attestationObject, AttestationStatement::class, $format, $context),
            $this->denormalizer->denormalize($authData, AuthenticatorData::class, $format, $context),
        );
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AttestationObject::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AttestationObject::class => true,
        ];
    }
}
