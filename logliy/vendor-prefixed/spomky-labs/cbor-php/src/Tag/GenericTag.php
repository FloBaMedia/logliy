<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\CBORObject;
use Logliy\CBOR\Tag;

final class GenericTag extends Tag
{
    public static function getTagId(): int
    {
        return -1;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }
}
