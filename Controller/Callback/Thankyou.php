<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Callback;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Post-signature return page (provider's redirectUrl).
 * No personal data exposed: only a generic message.
 */
class Thankyou implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): Redirect
    {
        $this->messageManager->addSuccessMessage(
            (string)__('Thank you! The signature has been received. You will get a confirmation once the document is available in your account.')
        );

        return $this->redirectFactory->create()->setPath('/');
    }
}
