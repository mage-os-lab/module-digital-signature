<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\TemplateInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface TemplateRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(TemplateInterface $template): TemplateInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $templateId): TemplateInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(TemplateInterface $template): void;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $templateId): void;
}
