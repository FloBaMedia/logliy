<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Event;

use Logliy\Psr\EventDispatcher\EventDispatcherInterface;

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
