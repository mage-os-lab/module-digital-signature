<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Webhook;

use MageOS\DigitalSignature\Model\Webhook\Client;
use MageOS\DigitalSignature\TestSupport\FakeCurl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private CurlFactory&MockObject $curlFactory;
    private FakeCurl $curl;
    private Client $client;

    protected function setUp(): void
    {
        $this->curl = new FakeCurl();
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);
        $this->client = new Client($this->curlFactory);
    }

    public function testDeliverSucceedsOn2xx(): void
    {
        $this->curl->status = 200;

        $result = $this->client->deliver('https://erp.example.com/hook', '{"a":1}', 'sha256=abc');

        self::assertTrue($result->isSuccess());
        self::assertSame('POST', $this->curl->lastMethod);
        self::assertSame('https://erp.example.com/hook', $this->curl->lastUrl);
        self::assertSame('{"a":1}', $this->curl->lastPayload);
        self::assertSame('sha256=abc', $this->curl->headers['X-Signature']);
        self::assertFalse($this->curl->options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($this->curl->options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $this->curl->options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $this->curl->options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testDeliverFailsOnHttpError(): void
    {
        $this->curl->status = 500;

        $result = $this->client->deliver('https://erp.example.com/hook', '{}', 'sha256=abc');

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('500', $result->getError());
        self::assertTrue($result->isRetryable());
    }

    /**
     * @dataProvider permanentStatusProvider
     */
    public function testDeliverMarksClientErrorsAsPermanent(int $status): void
    {
        $this->curl->status = $status;

        $result = $this->client->deliver('https://erp.example.com/hook', '{}', 'sha256=abc');

        self::assertFalse($result->isSuccess());
        self::assertFalse($result->isRetryable());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function permanentStatusProvider(): array
    {
        return [
            '401 unauthorized' => [401],
            '404 not found' => [404],
            '410 gone' => [410],
        ];
    }

    /**
     * @dataProvider retryableStatusProvider
     */
    public function testDeliverKeepsThrottlingStatusesRetryable(int $status): void
    {
        $this->curl->status = $status;

        $result = $this->client->deliver('https://erp.example.com/hook', '{}', 'sha256=abc');

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isRetryable());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function retryableStatusProvider(): array
    {
        return [
            '408 timeout' => [408],
            '429 too many requests' => [429],
            '503 unavailable' => [503],
        ];
    }

    public function testDeliverFailsOnTransportError(): void
    {
        $this->curl->transportError = new \Exception('connection refused');

        $result = $this->client->deliver('https://erp.example.com/hook', '{}', 'sha256=abc');

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('connection refused', $result->getError());
        self::assertTrue($result->isRetryable());
    }
}
