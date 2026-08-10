<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Denormalizer;

use function array_key_exists;
use function assert;
use Logliy\ParagonIE\ConstantTime\Base64UrlSafe;
use Logliy\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Logliy\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Logliy\Webauthn\Exception\InvalidDataException;
use Logliy\Webauthn\PublicKeyCredentialUserEntity;
use Logliy\Webauthn\Util\Base64;

final class PublicKeyCredentialUserEntityDenormalizer implements DenormalizerInterface, NormalizerInterface
{
    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{id?: string, name: string, displayName: string} $data */
        if (! array_key_exists('id', $data)) {
            return $data;
        }
        $data['id'] = Base64::decode($data['id']);

        return PublicKeyCredentialUserEntity::create($data['name'], $data['id'], $data['displayName']);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === PublicKeyCredentialUserEntity::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicKeyCredentialUserEntity::class => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        assert($object instanceof PublicKeyCredentialUserEntity);
        return [
            'id' => Base64UrlSafe::encodeUnpadded($object->id),
            'name' => $object->name,
            'displayName' => $object->displayName,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PublicKeyCredentialUserEntity;
    }
}
