<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\ByteStringObject;
use Logliy\CBOR\CBORObject;
use Logliy\CBOR\IndefiniteLengthByteStringObject;
use Logliy\CBOR\Tag;
use InvalidArgumentException;

final class CBOREncodingTag extends Tag
{
    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        if (! $object instanceof ByteStringObject && ! $object instanceof IndefiniteLengthByteStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Byte String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_ENCODED_CBOR;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_ENCODED_CBOR);

        return new self($ai, $data, $object);
    }
}
