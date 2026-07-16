<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Model\MergeField\Context;

interface MergeFieldProviderInterface
{
    public function getCode(): string;

    public function resolve(Context $context): string;

    public function isAvailableForTrigger(string $triggerCode): bool;
}
