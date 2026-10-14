<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Dossier;

use Magento\AdminNotification\Model\InboxFactory;
use Psr\Log\LoggerInterface;

/**
 * Admin notification bell entries for queued dossier exports. They point to
 * the grid by menu path, never to the file: the download is ACL-checked.
 */
class DossierExportNotifier
{
    public function __construct(
        private readonly InboxFactory $inboxFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function ready(int $exportId, int $documentCount, int $retentionHours): void
    {
        try {
            $this->inboxFactory->create()->addNotice(
                (string)__('Dossier export #%1 is ready', $exportId),
                (string)__(
                    'The dossier of %1 documents can be downloaded from Digital Signature > Dossier exports. It will be deleted after %2 hours.',
                    $documentCount,
                    $retentionHours
                )
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DigitalSignature: dossier export notification failed: ' . $e->getMessage());
        }
    }

    public function failed(int $exportId, string $reason): void
    {
        try {
            $this->inboxFactory->create()->addMajor(
                (string)__('Dossier export #%1 failed', $exportId),
                (string)__('%1 Start the export again from Digital Signature > Signature documents.', $reason)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DigitalSignature: dossier export notification failed: ' . $e->getMessage());
        }
    }
}
