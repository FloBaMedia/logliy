<?php

declare(strict_types=1);

namespace Logliy\CBOR\OtherObject;

use Logliy\CBOR\Normalizable;
use Logliy\CBOR\OtherObject as Base;

final class NullObject extends Base implements Normalizable
{
    public function __construct()
    {
        parent::__construct(self::OBJECT_NULL, null);
    }

    public static function create(): self
    {
        return new self();
    }

    public static function supportedAdditionalInformation(): array
    {
        return [self::OBJECT_NULL];
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data): Base
    {
        return new self();
    }

    public function normalize(): ?string
    {
        return null;
    }
}
