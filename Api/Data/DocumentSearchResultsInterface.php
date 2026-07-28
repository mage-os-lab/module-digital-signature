<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface DocumentSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \MageOS\DigitalSignature\Api\Data\DocumentInterface[]
     */
    public function getItems();

    /**
     * @param \MageOS\DigitalSignature\Api\Data\DocumentInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
