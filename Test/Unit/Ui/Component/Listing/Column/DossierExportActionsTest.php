<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Ui\Component\Listing\Column;

use MageOS\DigitalSignature\Ui\Component\Listing\Column\DossierExportActions;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class DossierExportActionsTest extends TestCase
{
    public function testDownloadOnlyForReadyExportsDeleteForAll(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params)
        );
        $column = new DossierExportActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $urlBuilder,
            [],
            ['name' => 'actions']
        );

        $items = $column->prepareDataSource(['data' => ['items' => [
            ['export_id' => 1, 'status' => 'ready'],
            ['export_id' => 2, 'status' => 'processing'],
        ]]])['data']['items'];

        self::assertSame('digitalsignature/dossierexport/download?id=1', $items[0]['actions']['download']['href']);
        self::assertSame('digitalsignature/dossierexport/delete?id=1', $items[0]['actions']['delete']['href']);
        self::assertTrue($items[0]['actions']['delete']['post']);
        self::assertArrayNotHasKey('download', $items[1]['actions']);
        self::assertArrayHasKey('delete', $items[1]['actions']);
    }
}
