<?php

declare(strict_types=1);

namespace Logliy\Webauthn\AttestationStatement;

use function count;
use function is_array;
use function is_string;
use Logliy\Psr\EventDispatcher\EventDispatcherInterface;
use Logliy\Webauthn\AuthenticatorData;
use Logliy\Webauthn\Event\AttestationStatementLoaded;
use Logliy\Webauthn\Event\CanDispatchEvents;
use Logliy\Webauthn\Event\NullEventDispatcher;
use Logliy\Webauthn\Exception\AttestationStatementLoadingException;
use Logliy\Webauthn\TrustPath\EmptyTrustPath;

final class NoneAttestationStatementSupport implements AttestationStatementSupport, CanDispatchEvents
{
    private EventDispatcherInterface $dispatcher;

    public function __construct()
    {
        $this->dispatcher = new NullEventDispatcher();
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    public static function create(): self
    {
        return new self();
    }

    public function name(): string
    {
        return 'none';
    }

    /**
     * @param array<string, mixed> $attestation
     */
    public function load(array $attestation): AttestationStatement
    {
        $format = $attestation['fmt'] ?? null;
        $attestationStatement = $attestation['attStmt'] ?? [];

        (is_string($format) && $format !== '') || throw AttestationStatementLoadingException::create(
            $attestation,
            'Invalid attestation object'
        );
        (is_array(
            $attestationStatement
        ) && $attestationStatement === []) || throw AttestationStatementLoadingException::create(
            $attestation,
            'Invalid attestation object'
        );

        $attestationStatement = AttestationStatement::createNone(
            $format,
            $attestationStatement,
            EmptyTrustPath::create()
        );
        $this->dispatcher->dispatch(AttestationStatementLoaded::create($attestationStatement));

        return $attestationStatement;
    }

    public function isValid(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData
    ): bool {
        return count($attestationStatement->attStmt) === 0;
    }
}
