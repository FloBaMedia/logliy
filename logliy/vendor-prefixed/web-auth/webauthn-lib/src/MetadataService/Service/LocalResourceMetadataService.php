<?php

declare(strict_types=1);

namespace Logliy\Webauthn\MetadataService\Service;

use function assert;
use function file_get_contents;
use Logliy\ParagonIE\ConstantTime\Base64;
use Logliy\Psr\EventDispatcher\EventDispatcherInterface;
use Logliy\Symfony\Component\Serializer\Encoder\JsonEncoder;
use Logliy\Symfony\Component\Serializer\SerializerInterface;
use Logliy\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Logliy\Webauthn\Denormalizer\WebauthnSerializerFactory;
use Logliy\Webauthn\Event\CanDispatchEvents;
use Logliy\Webauthn\Event\MetadataStatementFound;
use Logliy\Webauthn\Event\NullEventDispatcher;
use Logliy\Webauthn\Exception\MetadataStatementLoadingException;
use Logliy\Webauthn\Exception\MissingMetadataStatementException;
use Logliy\Webauthn\MetadataService\Statement\MetadataStatement;

final class LocalResourceMetadataService implements MetadataService, CanDispatchEvents
{
    private ?MetadataStatement $statement = null;

    private EventDispatcherInterface $dispatcher;

    private readonly SerializerInterface $serializer;

    public function __construct(
        private readonly string $filename,
        private readonly bool $isBase64Encoded = false,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create()
        ))->create();
        $this->dispatcher = new NullEventDispatcher();
    }

    public static function create(
        string $filename,
        bool $isBase64Encoded = false,
        ?SerializerInterface $serializer = null
    ): self {
        return new self($filename, $isBase64Encoded, $serializer);
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    public function list(): iterable
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();
        $aaguid = $this->statement->aaguid;
        if ($aaguid === null) {
            yield from [];
        } else {
            yield from [$aaguid];
        }
    }

    public function has(string $aaguid): bool
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();

        return $aaguid === $this->statement->aaguid;
    }

    public function get(string $aaguid): MetadataStatement
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();

        if ($aaguid === $this->statement->aaguid) {
            $this->dispatcher->dispatch(MetadataStatementFound::create($this->statement));

            return $this->statement;
        }

        throw MissingMetadataStatementException::create($aaguid);
    }

    private function loadData(): void
    {
        if ($this->statement !== null) {
            return;
        }

        $content = file_get_contents($this->filename);
        assert($content !== false, 'The file could not be read');
        if ($this->isBase64Encoded) {
            $content = Base64::decode($content, true);
        }
        $this->statement = $this->serializer->deserialize($content, MetadataStatement::class, JsonEncoder::FORMAT);
    }
}
