<?php

declare(strict_types=1);

namespace Logliy\Webauthn\AttestationStatement;

interface AttestationStatementSupportManagerAwareInterface
{
    public function setAttestationStatementSupportManager(AttestationStatementSupportManager $manager): void;
}
