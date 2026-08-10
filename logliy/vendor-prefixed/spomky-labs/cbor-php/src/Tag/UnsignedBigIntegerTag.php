<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\ByteStringObject;
use Logliy\CBOR\CBORObject;
use Logliy\CBOR\IndefiniteLengthByteStringObject;
use Logliy\CBOR\Normalizable;
use Logliy\CBOR\Tag;
use Logliy\CBOR\Utils;
use InvalidArgumentException;

final class UnsignedBigIntegerTag extends Tag implements Normalizable
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
        return self::TAG_UNSIGNED_BIG_NUM;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_UNSIGNED_BIG_NUM);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var ByteStringObject|IndefiniteLengthByteStringObject $object */
        $object = $this->object;

        return Utils::hexToString($object->normalize());
    }
}
