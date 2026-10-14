<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Exception\DossierExportAbortedException;
use MageOS\DigitalSignature\Model\Dossier\DossierExportConfig;
use MageOS\DigitalSignature\Model\Dossier\DossierExportNotifier;
use MageOS\DigitalSignature\Model\Dossier\DossierExportStateMachine;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\Service\DossierBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Builds a queued dossier export. Ownership of the job is taken and kept
 * through DossierExportStateMachine: if a heartbeat or the final transition
 * finds the job no longer processing (deleted from the grid, or declared
 * interrupted by the cleanup cron), the consumer stops, removes its file and
 * leaves the row alone.
 */
class DossierExportConsumer
{
    public function __construct(
        private readonly DossierExportRepositoryInterface $repository,
        private readonly DossierExportStateMachine $stateMachine,
        private readonly DossierBuilder $dossierBuilder,
        private readonly DossierFileLocator $fileLocator,
        private readonly DossierExportConfig $config,
        private readonly DossierExportNotifier $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $exportId): void
    {
        $id = (int)$exportId;
        try {
            $export = $this->repository->getById($id);
        } catch (NoSuchEntityException) {
            $this->logger->debug(sprintf('DigitalSignature: dossier export #%d no longer exists, skipped', $id));

            return;
        }

        if ($export->getStatus() !== DossierExportInterface::STATUS_QUEUED) {
            $this->logger->debug(sprintf('DigitalSignature: dossier export #%d is not queued, skipped', $id));

            return;
        }

        $relativePath = $this->fileLocator->newRelativePath('dossier-' . $id);
        if (!$this->stateMachine->claim($id, $relativePath)) {
            $this->logger->debug(sprintf('DigitalSignature: dossier export #%d already claimed, skipped', $id));

            return;
        }

        try {
            $this->dossierBuilder->buildToFile(
                $export->getDocumentIds(),
                $this->fileLocator->absolutePath($relativePath),
                $export->getRequestedBy(),
                function (int $processed) use ($id): void {
                    if (!$this->stateMachine->heartbeat($id, $processed)) {
                        throw new DossierExportAbortedException(
                            sprintf('Dossier export #%d is no longer processing', $id)
                        );
                    }
                }
            );

            $retentionHours = $this->config->getRetentionHours();
            if (!$this->stateMachine->markReady($id, $this->fileLocator->size($relativePath), $retentionHours)) {
                throw new DossierExportAbortedException(sprintf('Dossier export #%d is no longer processing', $id));
            }

            $this->notifier->ready($id, $export->getDocumentCount(), $retentionHours);
        } catch (DossierExportAbortedException $e) {
            $this->removeFile($relativePath);
            $this->logger->info('DigitalSignature: ' . $e->getMessage() . ', stopped');
        } catch (\Throwable $e) {
            $this->removeFile($relativePath);
            $this->logger->error(sprintf('DigitalSignature: dossier export #%d failed: %s', $id, $e->getMessage()));
            if ($this->stateMachine->markFailed($id, $e->getMessage())) {
                $this->notifier->failed($id, $e->getMessage());
            }
        }
    }

    private function removeFile(string $relativePath): void
    {
        try {
            $this->fileLocator->delete($relativePath);
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: could not delete dossier file: ' . $e->getMessage());
        }
    }
}
