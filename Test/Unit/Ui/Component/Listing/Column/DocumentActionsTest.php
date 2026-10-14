<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use MageOS\DigitalSignature\Model\AuditTrail;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\Collection as AuditTrailCollection;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\CollectionFactory as AuditTrailCollectionFactory;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use MageOS\DigitalSignature\Ui\Component\Listing\Column\DocumentActions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentActionsTest extends TestCase
{
    use ModelDoubles;

    private AuditTrailCollectionFactory&MockObject $auditTrailCollectionFactory;
    private DocumentActions $column;

    protected function setUp(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn(string $route, array $params = []): string => $route . '?' . http_build_query($params)
        );
        $this->auditTrailCollectionFactory = $this->createMock(AuditTrailCollectionFactory::class);

        $this->column = new DocumentActions(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            $urlBuilder,
            $this->auditTrailCollectionFactory,
            [],
            ['name' => 'actions']
        );
    }

    public function testAddsCertificateDownloadOnlyForDocumentsWithAFetchedCertificate(): void
    {
        $auditTrail = $this->newModel(AuditTrail::class);
        $auditTrail->setDocumentId(7);
        $auditTrail->setCertificatePath('order_1/document_7_certificate.pdf');
        $collection = $this->newCollection(AuditTrailCollection::class, [$auditTrail]);
        $this->auditTrailCollectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $result = $this->column->prepareDataSource(['data' => ['items' => [
            ['document_id' => 7, 'signed_pdf_path' => 'signed.pdf'],
            ['document_id' => 8, 'signed_pdf_path' => 'signed.pdf'],
        ]]]);

        $items = $result['data']['items'];
        self::assertSame(
            'digitalsignature/document/download?id=7&type=certificate',
            $items[0]['actions']['download_certificate']['href']
        );
        self::assertArrayNotHasKey('download_certificate', $items[1]['actions']);
    }

    public function testDoesNotQueryAuditTrailsForAnEmptyPage(): void
    {
        $this->auditTrailCollectionFactory->expects(self::never())->method('create');

        $result = $this->column->prepareDataSource(['data' => ['items' => []]]);

        self::assertSame([], $result['data']['items']);
    }
}
