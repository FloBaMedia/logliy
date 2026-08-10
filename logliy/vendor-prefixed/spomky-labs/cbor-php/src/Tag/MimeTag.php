<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\CBORObject;
use Logliy\CBOR\IndefiniteLengthTextStringObject;
use Logliy\CBOR\Normalizable;
use Logliy\CBOR\Tag;
use Logliy\CBOR\TextStringObject;
use InvalidArgumentException;

/**
 * @see \Logliy\CBOR\Test\Tag\MimeTagTest
 */
final class MimeTag extends Tag implements Normalizable
{
    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        if (! $object instanceof TextStringObject && ! $object instanceof IndefiniteLengthTextStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Byte String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_MIME;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_MIME);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var TextStringObject|IndefiniteLengthTextStringObject $object */
        $object = $this->object;

        return $object->normalize();
    }
}
