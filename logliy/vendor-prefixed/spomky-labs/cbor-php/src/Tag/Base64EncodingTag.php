<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\CBORObject;
use Logliy\CBOR\Tag;

final class Base64EncodingTag extends Tag
{
    public static function getTagId(): int
    {
        return self::TAG_ENCODED_BASE64;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_ENCODED_BASE64);

        return new self($ai, $data, $object);
    }
}
