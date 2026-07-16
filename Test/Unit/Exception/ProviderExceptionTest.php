<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Exception;

use MageOS\DigitalSignature\Exception\ProviderException;
use PHPUnit\Framework\TestCase;

class ProviderExceptionTest extends TestCase
{
    public function testRetryableFactory(): void
    {
        $exception = ProviderException::retryable(__('errore temporaneo'));

        self::assertTrue($exception->isRetryable());
    }

    public function testPermanentFactory(): void
    {
        $exception = ProviderException::permanent(__('errore permanente'));

        self::assertFalse($exception->isRetryable());
    }

    public function testCauseIsPreserved(): void
    {
        $cause = new \RuntimeException('timeout');

        $exception = ProviderException::retryable(__('errore di rete'), $cause);

        self::assertSame($cause, $exception->getPrevious());
    }
}
