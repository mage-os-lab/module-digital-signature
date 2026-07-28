<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider\AdobeSign;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\AdobeSign\Client;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\TestSupport\FakeCurl;
use MageOS\DigitalSignature\TestSupport\SequencedCurlFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private const STORE_ID = 1;
    private const API_ACCESS_POINT = 'https://api.na1.adobesign.com';

    private CacheInterface&MockObject $cache;
    private ProviderConfig&MockObject $config;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->config = $this->createMock(ProviderConfig::class);
    }

    private function makeClient(SequencedCurlFactory $curlFactory): Client
    {
        return new Client($curlFactory, new Json(), $this->cache, $this->config);
    }

    private function configureValidCredentials(): void
    {
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'client_id', self::STORE_ID, 'client-1'],
            ['adobesign', 'api_access_point', self::STORE_ID, self::API_ACCESS_POINT],
        ]);
        $this->config->method('getSecret')->willReturnMap([
            ['adobesign', 'client_secret', self::STORE_ID, 'secret-1'],
            ['adobesign', 'refresh_token', self::STORE_ID, 'refresh-1'],
        ]);
    }

    private function refreshCurl(): FakeCurl
    {
        $curl = new FakeCurl();
        $curl->body = '{"access_token":"tok-abc"}';

        return $curl;
    }

    private function cachedSession(): array
    {
        return ['access_token' => 'cached-tok', 'api_access_point' => self::API_ACCESS_POINT];
    }

    public function testCreateAgreementUploadsDocumentThenCreatesAgreement(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);
        $this->cache->expects(self::once())->method('save')->with(
            self::isType('string'),
            'adobesign_token_' . self::STORE_ID,
            [],
            3300
        );

        $uploadCurl = new FakeCurl();
        $uploadCurl->body = '{"transientDocumentId":"transient-1"}';

        $agreementCurl = new FakeCurl();
        $agreementCurl->body = '{"id":"agreement-1","status":"OUT_FOR_SIGNATURE"}';

        $factory = new SequencedCurlFactory($this->refreshCurl(), $uploadCurl, $agreementCurl);
        $client = $this->makeClient($factory);

        $response = $client->createAgreement(self::STORE_ID, ['name' => 'Test'], 'doc.pdf', '%PDF-1.4 contenuto');

        self::assertSame('agreement-1', $response['id']);
        self::assertSame(
            self::API_ACCESS_POINT . '/api/rest/v6/transientDocuments',
            $uploadCurl->lastUrl
        );
        self::assertStringContainsString('name="File-Name"', (string)$uploadCurl->lastPayload);
        self::assertStringContainsString('doc.pdf', (string)$uploadCurl->lastPayload);
        self::assertStringContainsString('%PDF-1.4 contenuto', (string)$uploadCurl->lastPayload);
        self::assertSame(
            self::API_ACCESS_POINT . '/api/rest/v6/agreements',
            $agreementCurl->lastUrl
        );
        self::assertStringContainsString('transient-1', (string)$agreementCurl->lastPayload);
        self::assertSame('Bearer tok-abc', $agreementCurl->headers['Authorization'] ?? null);
    }

    public function testUploadFailsWhenNoTransientDocumentIdReturned(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $uploadCurl = new FakeCurl();
        $uploadCurl->body = '{"error":"nope"}';

        $client = $this->makeClient(new SequencedCurlFactory($uploadCurl));

        try {
            $client->createAgreement(self::STORE_ID, ['name' => 'Test'], 'doc.pdf', '%PDF');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testGetAgreementStatusReusesCachedSession(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());
        $this->cache->expects(self::never())->method('save');

        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"SIGNED"}';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));
        $response = $client->getAgreementStatus(self::STORE_ID, 'agreement-1');

        self::assertSame('SIGNED', $response['status']);
        self::assertSame(
            self::API_ACCESS_POINT . '/api/rest/v6/agreements/agreement-1',
            $statusCurl->lastUrl
        );
        self::assertSame('Bearer cached-tok', $statusCurl->headers['Authorization'] ?? null);
    }

    public function testGetAgreementStatusIgnoresCorruptCacheAndReauthenticates(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn('{not valid json');
        $this->cache->expects(self::once())->method('save');

        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"OUT_FOR_SIGNATURE"}';

        $client = $this->makeClient(new SequencedCurlFactory($this->refreshCurl(), $statusCurl));
        $response = $client->getAgreementStatus(self::STORE_ID, 'agreement-1');

        self::assertSame('OUT_FOR_SIGNATURE', $response['status']);
    }

    public function testDownloadCombinedDocumentReturnsPdfBytes(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $downloadCurl = new FakeCurl();
        $downloadCurl->body = "%PDF-1.4\ncontenuto firmato";

        $client = $this->makeClient(new SequencedCurlFactory($downloadCurl));
        $pdf = $client->downloadCombinedDocument(self::STORE_ID, 'agreement-1');

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame('application/pdf', $downloadCurl->headers['Accept'] ?? null);
    }

    public function testDownloadCombinedDocumentRejectsEmptyBody(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $downloadCurl = new FakeCurl();
        $downloadCurl->body = '';

        $client = $this->makeClient(new SequencedCurlFactory($downloadCurl));

        $this->expectException(ProviderException::class);
        $client->downloadCombinedDocument(self::STORE_ID, 'agreement-1');
    }

    public function testCancelAgreementSendsPutWithReason(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $cancelCurl = new FakeCurl();
        $cancelCurl->body = '{}';

        $client = $this->makeClient(new SequencedCurlFactory($cancelCurl));
        $client->cancelAgreement(self::STORE_ID, 'agreement-1', 'annullato dal test');

        self::assertSame('PUT', $cancelCurl->options[CURLOPT_CUSTOMREQUEST] ?? null);
        self::assertStringContainsString('annullato dal test', (string)$cancelCurl->lastPayload);
        self::assertStringContainsString('CANCELLED', (string)$cancelCurl->lastPayload);
    }

    public function testCancelAgreementTolerates404(): void
    {
        $this->expectNotToPerformAssertions();

        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $cancelCurl = new FakeCurl();
        $cancelCurl->status = 404;

        $client = $this->makeClient(new SequencedCurlFactory($cancelCurl));
        $client->cancelAgreement(self::STORE_ID, 'agreement-1', 'document already absent');
    }

    public function testRefreshFailsWithIncompleteConfig(): void
    {
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'client_id', self::STORE_ID, ''],
            ['adobesign', 'api_access_point', self::STORE_ID, self::API_ACCESS_POINT],
        ]);
        $this->config->method('getSecret')->willReturn('secret');
        $this->cache->method('load')->willReturn(false);

        $client = $this->makeClient(new SequencedCurlFactory());

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testRefreshFailsWhenNoApiAccessPointConfigured(): void
    {
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'client_id', self::STORE_ID, 'client-1'],
            ['adobesign', 'api_access_point', self::STORE_ID, null],
        ]);
        $this->config->method('getSecret')->willReturnMap([
            ['adobesign', 'client_secret', self::STORE_ID, 'secret-1'],
            ['adobesign', 'refresh_token', self::STORE_ID, 'refresh-1'],
        ]);
        $this->cache->method('load')->willReturn(false);

        $client = $this->makeClient(new SequencedCurlFactory());

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testRefreshFailsWhenResponseHasNoAccessToken(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);

        $refreshCurl = new FakeCurl();
        $refreshCurl->body = '{"error":"invalid_grant"}';

        $client = $this->makeClient(new SequencedCurlFactory($refreshCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testRefreshRequestUsesConfiguredApiAccessPoint(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);

        $refreshCurl = $this->refreshCurl();
        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"SIGNED"}';

        $client = $this->makeClient(new SequencedCurlFactory($refreshCurl, $statusCurl));
        $client->getAgreementStatus(self::STORE_ID, 'agreement-1');

        self::assertSame(self::API_ACCESS_POINT . '/oauth/v2/refresh', $refreshCurl->lastUrl);
        self::assertStringContainsString('grant_type=refresh_token', (string)$refreshCurl->lastPayload);
        self::assertStringContainsString('refresh_token=refresh-1', (string)$refreshCurl->lastPayload);
    }

    public function testServerErrorIsRetryable(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->status = 500;

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testRateLimitIsRetryable(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->status = 429;

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testClientErrorIsPermanent(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->status = 400;
        $statusCurl->body = '{"error":"INVALID_REQUEST"}';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testTransportErrorIsRetryable(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->transportError = new \Exception('connection timeout');

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testNonJsonResponseIsPermanent(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->body = '<html>maintenance</html>';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testNonArrayJsonResponseIsPermanent(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn($this->cachedSessionJson());

        $statusCurl = new FakeCurl();
        $statusCurl->body = '"just a string"';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        $this->expectException(ProviderException::class);
        $client->getAgreementStatus(self::STORE_ID, 'agreement-1');
    }

    private function cachedSessionJson(): string
    {
        return (new Json())->serialize($this->cachedSession());
    }
}
