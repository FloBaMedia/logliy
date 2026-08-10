<?php

declare(strict_types=1);

namespace Logliy\CBOR;

interface Normalizable
{
    /**
     * @return mixed|null
     */
    public function normalize();
}
