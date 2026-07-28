<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\ApiConsumer;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use MageOS\DigitalSignature\Model\ApiConsumerFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::api_consumer';

    private const PERSIST_KEY = 'digitalsignature_apiconsumer';

    public function __construct(
        Action\Context $context,
        private readonly ApiConsumerRepositoryInterface $apiConsumerRepository,
        private readonly ApiConsumerFactory $apiConsumerFactory,
        private readonly DataPersistorInterface $dataPersistor
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

        $consumerId = (int)($data['consumer_id'] ?? 0);

        try {
            $consumer = $consumerId
                ? $this->apiConsumerRepository->getById($consumerId)
                : $this->apiConsumerFactory->create();

            $integrationId = (int)($data['integration_id'] ?? 0);
            $name = trim((string)($data['name'] ?? ''));
            if ($integrationId <= 0) {
                throw new LocalizedException(__('The Integration ID is required.'));
            }
            if ($name === '') {
                throw new LocalizedException(__('The name is required.'));
            }

            $consumer->setIntegrationId($integrationId);
            $consumer->setName($name);
            $consumer->setEnabled((bool)($data['enabled'] ?? false));
            $this->apiConsumerRepository->save($consumer);
            $consumerId = (int)$consumer->getConsumerId();

            $storeIds = array_map('intval', (array)($data['store_ids'] ?? []));
            $this->apiConsumerRepository->saveStoreIds($consumerId, $storeIds);

            $this->messageManager->addSuccessMessage(__('Integration saved.'));
            $this->dataPersistor->clear(self::PERSIST_KEY);

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['consumer_id' => $consumerId]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This integration no longer exists.'));

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Error while saving the integration.'));
        }

        $this->dataPersistor->set(self::PERSIST_KEY, $data);

        return $consumerId
            ? $resultRedirect->setPath('*/*/edit', ['consumer_id' => $consumerId])
            : $resultRedirect->setPath('*/*/new');
    }
}
