<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Order\View;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\TemplateRepositoryInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * "Signature Documents" tab in the admin order view: list of documents (history
 * included) with status, template, provider, PDF and manual actions.
 */
class Documents extends Template
{
    /** @var array<int, string> template name cache by id */
    private array $templateNames = [];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CollectionFactory $documentCollectionFactory,
        private readonly TemplateRepositoryInterface $templateRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder(): ?OrderInterface
    {
        $order = $this->registry->registry('current_order') ?? $this->registry->registry('order');

        return $order instanceof OrderInterface ? $order : null;
    }

    /**
     * Order documents, most recent first (history included).
     *
     * @return DocumentInterface[]
     */
    public function getDocuments(): array
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return [];
        }
        $collection = $this->documentCollectionFactory->create();
        $collection->addFieldToFilter(DocumentInterface::ORDER_ID, (int)$order->getId())
            ->setOrder(DocumentInterface::DOCUMENT_ID, 'DESC');

        return $collection->getItems();
    }

    public function isActive(DocumentInterface $document): bool
    {
        return $document->getIsActive();
    }

    public function getStatusLabel(string $status): string
    {
        $labels = Status::getLabels();

        return (string)($labels[$status] ?? $status);
    }

    public function getTemplateName(?int $templateId): string
    {
        if (!$templateId) {
            return '';
        }
        if (!isset($this->templateNames[$templateId])) {
            try {
                $this->templateNames[$templateId] = $this->templateRepository->getById($templateId)->getName();
            } catch (\Exception $e) {
                $this->templateNames[$templateId] = '#' . $templateId;
            }
        }

        return $this->templateNames[$templateId];
    }

    public function getOrderItemLabel(int $orderItemId): string
    {
        if ($orderItemId === DocumentInterface::ITEM_ID_CART_SCOPE) {
            return (string)__('Cart / Order');
        }
        $order = $this->getOrder();
        $item = $order ? $order->getItemById($orderItemId) : null;
        if ($item) {
            return sprintf('%s (%s)', $item->getName(), $item->getSku());
        }

        return '#' . $orderItemId;
    }

    public function getFormattedDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        return $this->formatDate($date, \IntlDateFormatter::SHORT, true);
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('digitalsignature/document/generate', ['order_id' => (int)($this->getOrder()?->getId() ?? 0)]);
    }

    public function getRegenerateUrl(): string
    {
        return $this->getUrl('digitalsignature/document/regenerate');
    }
}
