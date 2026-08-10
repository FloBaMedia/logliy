<?php

declare(strict_types=1);

namespace Logliy\Webauthn\MetadataService\Statement;

use Logliy\Webauthn\Exception\MetadataStatementLoadingException;

abstract class AbstractDescriptor
{
    public function __construct(
        public readonly ?int $maxRetries = null,
        public readonly ?int $blockSlowdown = null
    ) {
        $maxRetries >= 0 || throw MetadataStatementLoadingException::create(
            'Invalid data. The value of "maxRetries" must be a positive integer'
        );
        $blockSlowdown >= 0 || throw MetadataStatementLoadingException::create(
            'Invalid data. The value of "blockSlowdown" must be a positive integer'
        );
    }
}
