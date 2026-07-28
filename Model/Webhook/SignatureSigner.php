<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Webhook;

class SignatureSigner
{
    public function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
}
