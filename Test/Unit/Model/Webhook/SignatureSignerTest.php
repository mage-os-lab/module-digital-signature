<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Webhook;

use MageOS\DigitalSignature\Model\Webhook\SignatureSigner;
use PHPUnit\Framework\TestCase;

class SignatureSignerTest extends TestCase
{
    public function testSignIsDeterministicHmacSha256(): void
    {
        $signer = new SignatureSigner();
        $expected = 'sha256=' . hash_hmac('sha256', '{"a":1}', 'my-secret');

        self::assertSame($expected, $signer->sign('{"a":1}', 'my-secret'));
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $signer = new SignatureSigner();

        self::assertNotSame(
            $signer->sign('payload', 'secret-a'),
            $signer->sign('payload', 'secret-b')
        );
    }
}
