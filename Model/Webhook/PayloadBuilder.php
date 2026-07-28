<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Webhook;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use Magento\Framework\Serialize\Serializer\Json;

class PayloadBuilder
{
    public function __construct(private readonly Json $json)
    {
    }

    /**
     * @param DocumentInterface $document
     * @param string|null $statusFrom
     * @param string $statusTo
     * @param int $deliveryId id of the delivery row: exposed in the payload to
     *                        allow the receiver to deduplicate retries
     * @return string
     */
    public function build(
        DocumentInterface $document,
        ?string $statusFrom,
        string $statusTo,
        int $deliveryId
    ): string {
        return $this->json->serialize([
            'delivery_id' => $deliveryId,
            'document_id' => (int)$document->getDocumentId(),
            'order_id' => $document->getOrderId(),
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'provider_code' => $document->getProviderCode(),
            'timestamp' => date('c'),
        ]);
    }
}
