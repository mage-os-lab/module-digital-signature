<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Api\Data\TemplateInterface;
use MageOS\DigitalSignature\Api\TemplateRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use MageOS\DigitalSignature\Model\PdfUploader;
use MageOS\DigitalSignature\Model\TemplateFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    public function __construct(
        Action\Context $context,
        private readonly TemplateRepositoryInterface $templateRepository,
        private readonly TemplateFactory $templateFactory,
        private readonly TemplateResource $templateResource,
        private readonly PdfUploader $pdfUploader,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly \MageOS\DigitalSignature\Model\Pdf\TagReplacer $tagReplacer,
        private readonly \MageOS\DigitalSignature\Api\MergeFieldPoolInterface $mergeFieldPool,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly \Magento\Framework\Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $templateId = (int)($data['template_id'] ?? 0);
        // The working store travels in the form payload (hidden field store_id)
        $storeId = (int)($data['store_id'] ?? $this->getRequest()->getParam('store', 0));

        try {
            $template = $templateId
                ? $this->templateRepository->getById($templateId)
                : $this->templateFactory->create();

            // The template's master data fields are global: they are saved only at store=0
            if ($storeId === 0) {
                $template->setName(trim((string)($data['name'] ?? '')));
                $template->setIsActive((bool)($data['is_active'] ?? false));
                $template->setIsRequired((bool)($data['is_required'] ?? false));
                $template->setScope((string)($data['scope'] ?? TemplateInterface::SCOPE_CART));
                $trigger = (string)($data['trigger_code'] ?? '');
                $template->setTriggerCode($trigger === '' ? null : $trigger);
                if ($template->getName() === '') {
                    throw new LocalizedException(__('The template name is required.'));
                }
            }
            $this->templateRepository->save($template);
            $templateId = (int)$template->getTemplateId();

            $this->savePdfFile($templateId, $storeId, $data);

            $triggerCode = (string)($data['trigger_code'] ?? '');
            if ($triggerCode === '') {
                $triggerCode = (string)$this->scopeConfig->getValue(
                    'digital_signature/general/default_trigger',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            if ($triggerCode === '') {
                $triggerCode = \MageOS\DigitalSignature\Model\Template\Source\Trigger::ORDER_PLACED;
            }
            $this->validateMergeFieldsCompatibility($templateId, $storeId, $triggerCode);

            if ($storeId === 0 && $template->getScope() === TemplateInterface::SCOPE_PRODUCT) {
                $this->saveAssignedProducts($templateId, $data);
            }

            $this->messageManager->addSuccessMessage(__('Template saved.'));
            $this->dataPersistor->clear('digitalsignature_template');

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['template_id' => $templateId, 'store' => $storeId]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This template no longer exists.'));

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Error while saving the template.'));
        }

        $this->dataPersistor->set('digitalsignature_template', $data);

        return $templateId
            ? $resultRedirect->setPath('*/*/edit', ['template_id' => $templateId, 'store' => $storeId])
            : $resultRedirect->setPath('*/*/new');
    }

    /**
     * PDF handling for store scope: at store>0 the "use default" flag deletes the
     * specific row; a new upload (key "file") is moved from tmp and saved.
     */
    private function savePdfFile(int $templateId, int $storeId, array $data): void
    {
        $useDefault = $storeId > 0 && !empty($data['pdf_use_default']);
        if ($useDefault) {
            $this->templateResource->deletePdfPath($templateId, $storeId);

            return;
        }

        $fileData = $data['pdf_file'][0] ?? null;
        if (is_array($fileData) && !empty($fileData['file'])) {
            $relativePath = $this->pdfUploader->moveFileFromTmp($fileData['file'], true);
            $this->templateResource->savePdfPath($templateId, $storeId, $relativePath);
        }
    }

    private function saveAssignedProducts(int $templateId, array $data): void
    {
        if (!array_key_exists('template_products', $data)) {
            return;
        }
        $decoded = json_decode((string)$data['template_products'], true) ?: [];
        // The grid serializer produces {productId: position}
        $this->templateResource->saveAssignedProductIds($templateId, array_keys($decoded));
    }

    private function validateMergeFieldsCompatibility(int $templateId, int $storeId, string $triggerCode): void
    {
        $fileRow = $this->templateResource->getPdfPathForStore($templateId, $storeId);
        if ($fileRow === null) {
            return;
        }
        [, $templatePdfPath] = $fileRow;
        $mediaDir = $this->filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
        if (!$mediaDir->isExist($templatePdfPath)) {
            return;
        }

        $pdfContent = $mediaDir->readFile($templatePdfPath);
        $fieldTags = $this->tagReplacer->findFieldTags($pdfContent);
        if (empty($fieldTags)) {
            return;
        }

        foreach ($fieldTags as $code) {
            if (!$this->mergeFieldPool->has($code)) {
                throw new LocalizedException(
                    __('The merge field tag "{FIELD:%1}" is not supported or registered.', $code)
                );
            }
            $provider = $this->mergeFieldPool->get($code);
            if (!$provider->isAvailableForTrigger($triggerCode)) {
                throw new LocalizedException(
                    __(
                        'The merge field tag "{FIELD:%1}" is not compatible with the selected trigger "%2".',
                        $code,
                        $triggerCode
                    )
                );
            }
        }
    }
}
