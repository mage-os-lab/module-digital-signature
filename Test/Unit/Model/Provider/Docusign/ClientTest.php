<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider\Docusign;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\Docusign\Client;
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

    private CacheInterface&MockObject $cache;
    private ProviderConfig&MockObject $config;
    private string $privateKeyPem;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->config = $this->createMock(ProviderConfig::class);

        $keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyResource, $pem);
        $this->privateKeyPem = $pem;
    }

    private function makeClient(SequencedCurlFactory $curlFactory): Client
    {
        return new Client($curlFactory, new Json(), $this->cache, $this->config);
    }

    private function configureValidCredentials(): void
    {
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', self::STORE_ID, 'ikey-1'],
            ['docusign', 'user_id', self::STORE_ID, 'user-1'],
            ['docusign', 'environment', self::STORE_ID, 'demo'],
            ['docusign', 'account_id', self::STORE_ID, null],
        ]);
        $this->config->method('getSecret')->willReturn($this->privateKeyPem);
    }

    private function tokenCurl(): FakeCurl
    {
        $curl = new FakeCurl();
        $curl->body = '{"access_token":"tok-abc"}';

        return $curl;
    }

    private function userInfoCurl(string $accountId = 'acc-1', string $baseUri = 'https://demo.docusign.net'): FakeCurl
    {
        $curl = new FakeCurl();
        $curl->body = json_encode([
            'accounts' => [
                ['account_id' => $accountId, 'base_uri' => $baseUri, 'is_default' => true],
            ],
        ]);

        return $curl;
    }

    public function testCreateEnvelopeAuthenticatesAndCachesSession(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);
        $this->cache->expects(self::once())->method('save')->with(
            self::isType('string'),
            'docusign_jwt_' . self::STORE_ID,
            [],
            3000
        );

        $operationCurl = new FakeCurl();
        $operationCurl->body = '{"envelopeId":"env-1","status":"sent"}';

        $factory = new SequencedCurlFactory($this->tokenCurl(), $this->userInfoCurl(), $operationCurl);
        $client = $this->makeClient($factory);

        $response = $client->createEnvelope(self::STORE_ID, ['emailSubject' => 'test']);

        self::assertSame('env-1', $response['envelopeId']);
        self::assertSame(
            'https://demo.docusign.net/restapi/v2.1/accounts/acc-1/envelopes',
            $operationCurl->lastUrl
        );
        self::assertSame('Bearer tok-abc', $operationCurl->headers['Authorization'] ?? null);
    }

    public function testGetEnvelopeStatusReusesCachedSession(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'cached-tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));
        $this->cache->expects(self::never())->method('save');

        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"delivered"}';

        $factory = new SequencedCurlFactory($statusCurl);
        $client = $this->makeClient($factory);

        $response = $client->getEnvelopeStatus(self::STORE_ID, 'env-1');

        self::assertSame('delivered', $response['status']);
        self::assertSame(
            'https://demo.docusign.net/restapi/v2.1/accounts/acc-1/envelopes/env-1',
            $statusCurl->lastUrl
        );
        self::assertSame('Bearer cached-tok', $statusCurl->headers['Authorization'] ?? null);
    }

    public function testGetEnvelopeStatusIgnoresCorruptCacheAndReauthenticates(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn('{not valid json');
        $this->cache->expects(self::once())->method('save');

        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"sent"}';

        $factory = new SequencedCurlFactory($this->tokenCurl(), $this->userInfoCurl(), $statusCurl);
        $client = $this->makeClient($factory);

        $response = $client->getEnvelopeStatus(self::STORE_ID, 'env-1');

        self::assertSame('sent', $response['status']);
    }

    public function testDownloadDocumentReturnsPdfBytes(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $downloadCurl = new FakeCurl();
        $downloadCurl->body = "%PDF-1.4\ncontenuto firmato";

        $client = $this->makeClient(new SequencedCurlFactory($downloadCurl));

        $pdf = $client->downloadDocument(self::STORE_ID, 'env-1');

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame('application/pdf', $downloadCurl->headers['Accept'] ?? null);
    }

    public function testDownloadDocumentRejectsEmptyBody(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $downloadCurl = new FakeCurl();
        $downloadCurl->body = '';

        $client = $this->makeClient(new SequencedCurlFactory($downloadCurl));

        $this->expectException(ProviderException::class);
        $client->downloadDocument(self::STORE_ID, 'env-1');
    }

    public function testVoidEnvelopeSendsPutWithReason(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $voidCurl = new FakeCurl();
        $voidCurl->body = '{}';

        $client = $this->makeClient(new SequencedCurlFactory($voidCurl));
        $client->voidEnvelope(self::STORE_ID, 'env-1', 'annullato dal test');

        self::assertSame('PUT', $voidCurl->options[CURLOPT_CUSTOMREQUEST] ?? null);
        self::assertStringContainsString('annullato dal test', (string)$voidCurl->lastPayload);
    }

    public function testVoidEnvelopeTolerates404(): void
    {
        $this->expectNotToPerformAssertions();

        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $voidCurl = new FakeCurl();
        $voidCurl->status = 404;

        $client = $this->makeClient(new SequencedCurlFactory($voidCurl));
        $client->voidEnvelope(self::STORE_ID, 'env-1', 'document already absent');
    }

    public function testAuthenticationFailsWithIncompleteConfig(): void
    {
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', self::STORE_ID, ''],
            ['docusign', 'user_id', self::STORE_ID, 'user-1'],
            ['docusign', 'environment', self::STORE_ID, 'demo'],
        ]);
        $this->config->method('getSecret')->willReturn($this->privateKeyPem);
        $this->cache->method('load')->willReturn(false);

        $client = $this->makeClient(new SequencedCurlFactory());

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testAuthenticationFailsWithInvalidPrivateKey(): void
    {
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', self::STORE_ID, 'ikey-1'],
            ['docusign', 'user_id', self::STORE_ID, 'user-1'],
            ['docusign', 'environment', self::STORE_ID, 'demo'],
        ]);
        $this->config->method('getSecret')->willReturn('not-a-valid-pem');
        $this->cache->method('load')->willReturn(false);

        $client = $this->makeClient(new SequencedCurlFactory());

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testAuthenticationFailsWhenTokenResponseHasNoAccessToken(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);

        $tokenCurl = new FakeCurl();
        $tokenCurl->body = '{"error":"invalid_grant"}';

        $client = $this->makeClient(new SequencedCurlFactory($tokenCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testAuthenticationFailsWhenNoAccountsReturned(): void
    {
        $this->configureValidCredentials();
        $this->cache->method('load')->willReturn(false);

        $userInfoCurl = new FakeCurl();
        $userInfoCurl->body = '{"accounts":[]}';

        $client = $this->makeClient(new SequencedCurlFactory($this->tokenCurl(), $userInfoCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testAuthenticationSelectsConfiguredAccountOverDefault(): void
    {
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', self::STORE_ID, 'ikey-1'],
            ['docusign', 'user_id', self::STORE_ID, 'user-1'],
            ['docusign', 'environment', self::STORE_ID, 'demo'],
            ['docusign', 'account_id', self::STORE_ID, 'acc-target'],
        ]);
        $this->config->method('getSecret')->willReturn($this->privateKeyPem);
        $this->cache->method('load')->willReturn(false);

        $userInfoCurl = new FakeCurl();
        $userInfoCurl->body = json_encode([
            'accounts' => [
                ['account_id' => 'acc-default', 'base_uri' => 'https://demo.docusign.net', 'is_default' => true],
                ['account_id' => 'acc-target', 'base_uri' => 'https://other.docusign.net', 'is_default' => false],
            ],
        ]);

        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"sent"}';

        $factory = new SequencedCurlFactory($this->tokenCurl(), $userInfoCurl, $statusCurl);
        $client = $this->makeClient($factory);

        $client->getEnvelopeStatus(self::STORE_ID, 'env-1');

        self::assertSame(
            'https://other.docusign.net/restapi/v2.1/accounts/acc-target/envelopes/env-1',
            $statusCurl->lastUrl
        );
    }

    public function testUsesProductionAuthServerWhenConfigured(): void
    {
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', self::STORE_ID, 'ikey-1'],
            ['docusign', 'user_id', self::STORE_ID, 'user-1'],
            ['docusign', 'environment', self::STORE_ID, 'production'],
            ['docusign', 'account_id', self::STORE_ID, null],
        ]);
        $this->config->method('getSecret')->willReturn($this->privateKeyPem);
        $this->cache->method('load')->willReturn(false);

        $tokenCurl = $this->tokenCurl();
        $userInfoCurl = $this->userInfoCurl();
        $statusCurl = new FakeCurl();
        $statusCurl->body = '{"status":"sent"}';

        $client = $this->makeClient(new SequencedCurlFactory($tokenCurl, $userInfoCurl, $statusCurl));
        $client->getEnvelopeStatus(self::STORE_ID, 'env-1');

        self::assertSame('https://account.docusign.com/oauth/token', $tokenCurl->lastUrl);
        self::assertSame('https://account.docusign.com/oauth/userinfo', $userInfoCurl->lastUrl);
    }

    public function testServerErrorIsRetryable(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->status = 500;

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testRateLimitIsRetryable(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->status = 429;

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testClientErrorIsPermanent(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->status = 400;
        $statusCurl->body = '{"error":"INVALID_REQUEST"}';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testTransportErrorIsRetryable(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->transportError = new \Exception('connection timeout');

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertTrue($e->isRetryable());
        }
    }

    public function testNonJsonResponseIsPermanent(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->body = '<html>maintenance</html>';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        try {
            $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testNonArrayJsonResponseIsPermanent(): void
    {
        $this->configureValidCredentials();
        $session = ['access_token' => 'tok', 'base_url' => 'https://demo.docusign.net', 'account_id' => 'acc-1'];
        $this->cache->method('load')->willReturn((new Json())->serialize($session));

        $statusCurl = new FakeCurl();
        $statusCurl->body = '"just a string"';

        $client = $this->makeClient(new SequencedCurlFactory($statusCurl));

        $this->expectException(ProviderException::class);
        $client->getEnvelopeStatus(self::STORE_ID, 'env-1');
    }
}
