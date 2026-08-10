<?php

declare(strict_types=1);

namespace Logliy\Webauthn\MetadataService;

use Logliy\Psr\Log\LoggerInterface;

interface CanLogData
{
    public function setLogger(LoggerInterface $logger): void;
}
