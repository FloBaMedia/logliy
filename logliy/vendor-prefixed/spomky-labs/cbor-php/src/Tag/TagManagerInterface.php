<?php

declare(strict_types=1);

namespace Logliy\CBOR\Tag;

use Logliy\CBOR\CBORObject;

interface TagManagerInterface
{
    public function createObjectForValue(int $additionalInformation, ?string $data, CBORObject $object): TagInterface;
}
