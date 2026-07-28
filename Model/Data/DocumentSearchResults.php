<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Data;

use MageOS\DigitalSignature\Api\Data\DocumentSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

class DocumentSearchResults extends SearchResults implements DocumentSearchResultsInterface
{
}
