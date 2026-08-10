<?php

declare(strict_types=1);

namespace Logliy\Cose\Algorithm\Signature;

use Logliy\Cose\Algorithm\Algorithm;
use Logliy\Cose\Key\Key;

interface Signature extends Algorithm
{
    public function sign(string $data, Key $key): string;

    public function verify(string $data, Key $key, string $signature): bool;
}
