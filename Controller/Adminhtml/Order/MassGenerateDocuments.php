<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Order;

use MageOS\DigitalSignature\Model\Service\DocumentManager;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Controller\Adminhtml\Order\AbstractMassAction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

/**
 * Mass action on the orders grid: generates the signature documents (MANUAL
 * trigger) for the selected orders. The heavy lifting is queued by the
 * DocumentManager anyway, here it just takes charge of the requests.
 */
class MassGenerateDocuments extends AbstractMassAction
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document';

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context, $filter);
        $this->collectionFactory = $collectionFactory;
    }

    protected function massAction(AbstractCollection $collection)
    {
        $processed = 0;
        $failed = 0;

        foreach ($collection->getItems() as $order) {
            try {
                $this->documentManager->generateManual($order);
                $processed++;
            } catch (\Exception $e) {
                $failed++;
                $this->logger->error(
                    sprintf(
                        'DigitalSignature: mass action ordine #%s fallita: %s',
                        $order->getId(),
                        $e->getMessage()
                    )
                );
            }
        }

        if ($processed) {
            $this->messageManager->addSuccessMessage(
                __('Signature document generation queued for %1 order(s).', $processed)
            );
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(
                __('Could not queue %1 order(s) (see log).', $failed)
            );
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('sales/order/index');

        return $resultRedirect;
    }
}
