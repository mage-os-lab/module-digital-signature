<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Order;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Elenco dei documenti firmati di un ordine nell'area cliente "i miei ordini",
 * con link di download del PDF firmato (controller autorizzato).
 */
class SignedDocuments extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CollectionFactory $documentCollectionFactory,
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
     * Documenti firmati e scaricabili dell'ordine corrente.
     *
     * @return DocumentInterface[]
     */
    public function getSignedDocuments(): array
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return [];
        }

        $collection = $this->documentCollectionFactory->create();
        $collection->addFieldToFilter(DocumentInterface::ORDER_ID, (int)$order->getId())
            ->addFieldToFilter(DocumentInterface::STATUS, Status::SIGNED)
            ->addFieldToFilter(DocumentInterface::SIGNED_PDF_PATH, ['neq' => ''])
            ->addFieldToFilter(DocumentInterface::SIGNED_PDF_PATH, ['notnull' => true])
            ->setOrder(DocumentInterface::DOCUMENT_ID, 'DESC');

        return $collection->getItems();
    }

    public function hasSignedDocuments(): bool
    {
        return $this->getSignedDocuments() !== [];
    }

    public function getDownloadUrl(DocumentInterface $document): string
    {
        return $this->getUrl(
            'digitalsignature/order/downloadSigned',
            ['id' => (int)$document->getDocumentId()]
        );
    }

    public function getItemLabel(DocumentInterface $document): string
    {
        $orderItemId = $document->getOrderItemId();
        if ($orderItemId === DocumentInterface::ITEM_ID_CART_SCOPE) {
            return (string)__('Contratto d\'ordine');
        }

        $order = $this->getOrder();
        $item = $order ? $order->getItemById($orderItemId) : null;
        if ($item) {
            return (string)$item->getName();
        }

        return (string)__('Contratto');
    }
}
