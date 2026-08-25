<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists on the quote the customer's choice "I want the contract to sign".
 * Called via AJAX (POST, form key) from the Luma and Hyva checkbox on cart/
 * minicart/checkout. The final validation remains server-side at order placement.
 */
class SetRequested implements HttpPostActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly JsonFactory $resultJsonFactory,
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $requested = $this->request->getParam('requested');
        $requested = ($requested === '1' || $requested === 1 || $requested === true || $requested === 'true');

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId()) {
                return $result->setData(['success' => false, 'message' => __('Cart not available.')]);
            }
            $quote->setData('digitalsignature_requested', $requested ? 1 : 0);
            $this->quoteRepository->save($quote);

            return $result->setData(['success' => true, 'requested' => $requested]);
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: error saving signature opt-in: ' . $e->getMessage());

            return $result->setData(['success' => false, 'message' => __('Unable to save your choice.')]);
        }
    }
}
