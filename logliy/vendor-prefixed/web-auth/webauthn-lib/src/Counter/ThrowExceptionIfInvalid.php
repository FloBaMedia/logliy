<?php

declare(strict_types=1);

namespace Logliy\Webauthn\Counter;

use Logliy\Psr\Log\LoggerInterface;
use Logliy\Psr\Log\NullLogger;
use function trigger_deprecation;
use Logliy\Webauthn\CredentialRecord;
use Logliy\Webauthn\Exception\CounterException;
use Logliy\Webauthn\MetadataService\CanLogData;
use Logliy\Webauthn\PublicKeyCredentialSource;

final class ThrowExceptionIfInvalid implements CounterChecker, CanLogData
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($credentialRecord instanceof PublicKeyCredentialSource) {
            logliy_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::check()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }

        try {
            $currentCounter > $credentialRecord->counter || throw CounterException::create(
                $currentCounter,
                $credentialRecord->counter,
                'Invalid counter.'
            );
        } catch (CounterException $throwable) {
            $this->logger->error('The counter is invalid', [
                'current' => $currentCounter,
                'new' => $credentialRecord->counter,
            ]);
            throw $throwable;
        }
    }
}
