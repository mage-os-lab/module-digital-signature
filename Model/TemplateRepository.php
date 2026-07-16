<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\TemplateInterface;
use MageOS\DigitalSignature\Api\TemplateRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class TemplateRepository implements TemplateRepositoryInterface
{
    public function __construct(
        private readonly TemplateResource $resource,
        private readonly TemplateFactory $templateFactory
    ) {
    }

    public function save(TemplateInterface $template): TemplateInterface
    {
        try {
            $this->resource->save($template);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Impossibile salvare il template: %1', $e->getMessage()), $e);
        }

        return $template;
    }

    public function getById(int $templateId): TemplateInterface
    {
        $template = $this->templateFactory->create();
        $this->resource->load($template, $templateId);
        if (!$template->getTemplateId()) {
            throw new NoSuchEntityException(__('Template con id "%1" inesistente.', $templateId));
        }

        return $template;
    }

    public function delete(TemplateInterface $template): void
    {
        try {
            $this->resource->delete($template);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Impossibile eliminare il template: %1', $e->getMessage()), $e);
        }
    }

    public function deleteById(int $templateId): void
    {
        $this->delete($this->getById($templateId));
    }
}
