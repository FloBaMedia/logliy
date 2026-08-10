<?php

declare(strict_types=1);

namespace Logliy\Webauthn\MetadataService;

use Logliy\Webauthn\MetadataService\Statement\StatusReport;

interface StatusReportRepository
{
    /**
     * @return StatusReport[]
     */
    public function findStatusReportsByAAGUID(string $aaguid): array;
}
