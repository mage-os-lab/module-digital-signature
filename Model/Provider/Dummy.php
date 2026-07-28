<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Test provider: simulates the entire signing cycle locally, without
 * external services. The document becomes "signed" after a configurable delay.
 *
 * MUST NOT be used in production: it is only selectable if the "allow"
 * configuration flag is explicitly enabled.
 */
class Dummy implements SignProviderInterface
{
    public const CODE = 'dummy';

    public const RAW_IN_PROGRESS = 'DUMMY_IN_PROGRESS';
    public const RAW_SIGNED = 'DUMMY_SIGNED';

    public function __construct(
        private readonly ProviderConfig $config,
        private readonly DocumentStorageInterface $storage,
        private readonly Random $random,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('Dummy (test only)');
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isSetFlag(self::CODE, 'allow', $storeId);
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        $this->assertAllowed($document);
        if (!str_starts_with($pdfContent, '%PDF')) {
            throw ProviderException::permanent(__('Dummy: the content to send is not a PDF.'));
        }

        // The "process id" embeds the start time: it is used to simulate
        // the transition to signed after the configured delay.
        $processId = sprintf('dummy-%d-%s', $this->dateTime->gmtTimestamp(), $this->random->getRandomString(16));

        return new StartResult($processId);
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        $this->assertAllowed($document);
        $processId = $document->getProviderProcessId();
        if ($processId === null || !preg_match('/^dummy-(\d+)-/', $processId, $matches)) {
            throw ProviderException::permanent(__('Dummy: missing or unrecognized process id.'));
        }
        $startedAt = (int)$matches[1];
        $delay = max(0, (int)($this->config->get(self::CODE, 'auto_sign_delay', $document->getStoreId()) ?? 60));
        $signed = ($this->dateTime->gmtTimestamp() - $startedAt) >= $delay;

        return new StatusResult($signed ? self::RAW_SIGNED : self::RAW_IN_PROGRESS);
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        $this->assertAllowed($document);
        $pdfPath = $document->getPdfPath();
        if ($pdfPath === null) {
            throw ProviderException::permanent(__('Dummy: the document has no generated PDF.'));
        }
        if (!$this->storage->exists($pdfPath)) {
            throw ProviderException::permanent(__('Dummy: generated PDF not found in storage.'));
        }

        // Simulated "signature": returns the generated PDF as-is
        return $this->storage->read($pdfPath);
    }

    public function cancel(DocumentInterface $document): void
    {
        // No remote state to cancel
    }

    public function mapStatus(string $providerStatus): ?string
    {
        return match ($providerStatus) {
            self::RAW_SIGNED => Status::SIGNED,
            self::RAW_IN_PROGRESS => Status::SENT,
            default => null,
        };
    }

    private function assertAllowed(DocumentInterface $document): void
    {
        if (!$this->isEnabled($document->getStoreId())) {
            throw ProviderException::permanent(
                __('The dummy provider is not enabled: enable it explicitly in the configuration (test environments only).')
            );
        }
    }
}
