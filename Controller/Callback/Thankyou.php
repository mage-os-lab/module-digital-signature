<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Callback;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Pagina di rientro post-firma (redirectUrl del provider).
 * Nessun dato personale esposto: solo un messaggio generico.
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
            (string)__('Grazie! La firma è stata acquisita. Riceverai conferma quando il documento sarà disponibile nel tuo account.')
        );

        return $this->redirectFactory->create()->setPath('/');
    }
}
