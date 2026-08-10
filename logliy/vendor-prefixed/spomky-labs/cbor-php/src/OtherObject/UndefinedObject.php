<?php

declare(strict_types=1);

namespace Logliy\CBOR\OtherObject;

use Logliy\CBOR\OtherObject as Base;

final class UndefinedObject extends Base
{
    public function __construct()
    {
        parent::__construct(self::OBJECT_UNDEFINED, null);
    }

    public static function create(): self
    {
        return new self();
    }

    public static function supportedAdditionalInformation(): array
    {
        return [self::OBJECT_UNDEFINED];
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data): Base
    {
        return new self();
    }
}
