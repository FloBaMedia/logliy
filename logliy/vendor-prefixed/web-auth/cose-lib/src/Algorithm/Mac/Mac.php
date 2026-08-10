<?php

declare(strict_types=1);

namespace Logliy\Cose\Algorithm\Mac;

use Logliy\Cose\Algorithm\Algorithm;
use Logliy\Cose\Key\Key;

interface Mac extends Algorithm
{
    public function hash(string $data, Key $key): string;

    public function verify(string $data, Key $key, string $signature): bool;
}
