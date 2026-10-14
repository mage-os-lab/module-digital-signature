<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\TestCase;

class DossierExportTest extends TestCase
{
    use ModelDoubles;

    public function testDocumentIdsRoundTripAsIntegersAndSetTheCount(): void
    {
        $export = $this->newModel(DossierExport::class);
        $export->setDocumentIds(['7', 9, '12']);

        self::assertSame([7, 9, 12], $export->getDocumentIds());
        self::assertSame(3, $export->getDocumentCount());
        self::assertSame('[7,9,12]', $export->getData(DossierExportInterface::DOCUMENT_IDS));
    }

    public function testMissingOrCorruptDocumentIdsReadAsEmpty(): void
    {
        $export = $this->newModel(DossierExport::class);
        self::assertSame([], $export->getDocumentIds());

        $export->setData(DossierExportInterface::DOCUMENT_IDS, '{not json');
        self::assertSame([], $export->getDocumentIds());
    }

    public function testNullableFieldsStayNull(): void
    {
        $export = $this->newModel(DossierExport::class);

        self::assertNull($export->getExportId());
        self::assertNull($export->getRequestedBy());
        self::assertNull($export->getRequestedByUserId());
        self::assertNull($export->getFilePath());
    }
}
