<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\TestSupport;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Test double for CurlFactory: each call to create() returns the next
 * queued FakeCurl. Used for flows that perform multiple HTTP requests in
 * sequence with different responses (e.g. DocuSign JWT authentication:
 * OAuth token + userinfo + API call).
 */
class SequencedCurlFactory extends CurlFactory
{
    /** @var FakeCurl[] */
    private array $queue;

    /** @var FakeCurl[] */
    public array $created = [];

    public function __construct(FakeCurl ...$queue)
    {
        $this->queue = $queue;
    }

    public function create(array $data = []): FakeCurl
    {
        $curl = array_shift($this->queue) ?? new FakeCurl();
        $this->created[] = $curl;

        return $curl;
    }
}
