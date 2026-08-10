<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Event;

use Logliy\Psr\EventDispatcher\EventDispatcherInterface;

interface CanDispatchEvents
{
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void;
}
