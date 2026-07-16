<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider\WsSign;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\WsSign\Client;
use MageOS\DigitalSignature\TestSupport\FakeCurl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private FakeCurl $curl;
    private Client $client;

    protected function setUp(): void
    {
        $this->curl = new FakeCurl();
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);
        $this->client = new Client($curlFactory, new Json());
    }

    public function testFetchTokenReturnsAccessToken(): void
    {
        $this->curl->body = '{"access_token":"tok123"}';

        $token = $this->client->fetchToken('https://ws.example.com/', 'tenant one', 'user', 'pass');

        self::assertSame('tok123', $token);
        self::assertSame('https://ws.example.com/api/token/tenant%20one', $this->curl->lastUrl);
        self::assertStringContainsString('grant_type=password', (string)$this->curl->lastPayload);
        self::assertStringContainsString('username=user', (string)$this->curl->lastPayload);
    }

    public function testFetchTokenWithoutAccessTokenIsPermanent(): void
    {
        $this->curl->body = '{"error":"invalid_grant"}';

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testServerErrorIsRetryable(): void
    {
        $this->curl->status = 500;

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testRateLimitIsRetryable(): void
    {
        $this->curl->status = 429;

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testClientErrorIsPermanent(): void
    {
        $this->curl->status = 400;

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testTransportErrorIsRetryable(): void
    {
        $this->curl->transportError = new \Exception('connection timeout');

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testNonJsonResponseIsPermanent(): void
    {
        $this->curl->body = '<html>maintenance</html>';

        try {
            $this->client->fetchToken('https://ws.example.com', 't', 'u', 'p');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testGetDocumentReturnsNullOn404(): void
    {
        $this->curl->status = 404;

        self::assertNull($this->client->getDocument('https://ws.example.com', 'tok', 'guid-1'));
    }

    public function testGetDocumentReturnsDecodedPayload(): void
    {
        $this->curl->body = '{"data":{"status":"OPEN"}}';

        $data = $this->client->getDocument('https://ws.example.com', 'tok', 'guid-1');

        self::assertSame(['data' => ['status' => 'OPEN']], $data);
        self::assertSame('https://ws.example.com/api/v2/consumer/document/guid-1', $this->curl->lastUrl);
        self::assertSame('Bearer tok', $this->curl->headers['Authorization'] ?? null);
    }

    public function testUploadDocumentReturnsGuid(): void
    {
        $this->curl->body = '{"message":"DOCUMENT_ADDED","data":{"documents":[{"guid":"abc-123"}]}}';

        $guid = $this->client->uploadDocument('https://ws.example.com', 'tok', 'owner', 'file.pdf', '%PDF-1.4');

        self::assertSame('abc-123', $guid);
    }

    public function testUploadDocumentRejectedIsPermanent(): void
    {
        $this->curl->body = '{"message":"QUOTA_EXCEEDED"}';

        try {
            $this->client->uploadDocument('https://ws.example.com', 'tok', 'owner', 'file.pdf', '%PDF-1.4');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testDownloadDocumentRejectsNonPdfContent(): void
    {
        $this->curl->body = '<html>not a pdf</html>';

        $this->expectException(ProviderException::class);

        $this->client->downloadDocument('https://ws.example.com', 'tok', 'guid-1');
    }

    public function testDownloadDocumentReturnsPdf(): void
    {
        $this->curl->body = "%PDF-1.4\ncontenuto firmato";

        $pdf = $this->client->downloadDocument('https://ws.example.com', 'tok', 'guid-1');

        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testDeleteDocumentToleratesMissingDocument(): void
    {
        $this->expectNotToPerformAssertions();

        $this->curl->status = 404;
        $this->client->deleteDocument('https://ws.example.com', 'tok', 'guid-1');
    }
}
