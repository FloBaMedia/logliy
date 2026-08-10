<?php

declare(strict_types=1);

namespace Logliy\Webauthn\MetadataService;

use Logliy\Webauthn\MetadataService\Statement\MetadataStatement;

interface MetadataStatementRepository
{
    public function findOneByAAGUID(string $aaguid): ?MetadataStatement;
}
