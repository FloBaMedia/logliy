<?php

declare(strict_types=1);

namespace Logliy\Webauthn\CeremonyStep;

interface TopOriginValidator
{
    public function validate(string $topOrigin): void;
}
