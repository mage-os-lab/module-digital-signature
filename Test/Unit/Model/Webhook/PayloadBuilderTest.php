<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Webhook;

use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Webhook\PayloadBuilder;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    public function testBuildSerializesDocumentFields(): void
    {
        $document = new FakeDocument();
        $document->documentId = 7;
        $document->setOrderId(42);
        $document->setProviderCode('wssign');
        $document->setStatus(Status::SIGNED);

        $builder = new PayloadBuilder(new Json());
        $decoded = json_decode($builder->build($document, Status::SENT, Status::SIGNED, 99), true);

        self::assertSame(99, $decoded['delivery_id']);
        self::assertSame(7, $decoded['document_id']);
        self::assertSame(42, $decoded['order_id']);
        self::assertSame(Status::SENT, $decoded['status_from']);
        self::assertSame(Status::SIGNED, $decoded['status_to']);
        self::assertSame('wssign', $decoded['provider_code']);
        self::assertArrayHasKey('timestamp', $decoded);
    }

    public function testBuildAllowsNullStatusFromForFirstTransition(): void
    {
        $document = new FakeDocument();
        $document->documentId = 8;

        $builder = new PayloadBuilder(new Json());
        $decoded = json_decode($builder->build($document, null, Status::PENDING, 1), true);

        self::assertNull($decoded['status_from']);
    }
}
