<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Callback;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Server-to-server callback from signature providers.
 *
 * The callback is a PING: the payload is never used; if the checks
 * pass, a polling job is queued that reads the status from the provider API.
 * Response is always a uniform HTTP 200 (no oracle on id/token validity).
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    private const DEDUP_CACHE_PREFIX = 'digitalsignature_cb_';
    private const DEDUP_TTL_SECONDS = 60;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ProviderConfig $providerConfig,
        private readonly Publisher $publisher,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        try {
            $this->handle();
        } catch (\Exception $e) {
            // Never expose details externally; the detail is in the logs
            $this->logger->warning('DigitalSignature: callback error: ' . $e->getMessage());
        }

        return $this->jsonFactory->create()->setData(['status' => 'ok']);
    }

    private function handle(): void
    {
        $documentId = (int)$this->request->getParam('id');
        $token = (string)$this->request->getParam('token', '');
        if ($documentId <= 0 || $token === '') {
            return;
        }

        try {
            $document = $this->documentRepository->getById($documentId);
        } catch (NoSuchEntityException) {
            return;
        }

        $storedHash = $document->getCallbackTokenHash();
        if ($storedHash === null || !hash_equals($storedHash, hash('sha256', $token))) {
            return;
        }
        if (!$this->isIpAllowed($document->getProviderCode())) {
            $this->logger->warning(
                'DigitalSignature: callback blocked by IP allowlist for document ' . $documentId
            );

            return;
        }
        if (Status::isFinal($document->getStatus())) {
            return;
        }

        // Dedup: at most one refresh job per document within the TTL window
        $cacheKey = self::DEDUP_CACHE_PREFIX . $documentId;
        if ($this->cache->load($cacheKey)) {
            return;
        }
        $this->cache->save('1', $cacheKey, [], self::DEDUP_TTL_SECONDS);

        $this->documentRepository->addLog($document, 'callback', null, null, 'Callback ricevuta dal provider');
        $this->publisher->publishRefresh($documentId);
    }

    private function isIpAllowed(string $providerCode): bool
    {
        $allowedIps = array_filter(array_map(
            'trim',
            explode(',', (string)($this->providerConfig->get($providerCode, 'allowed_ips') ?? ''))
        ));
        if (!$allowedIps) {
            return true;
        }

        return in_array($this->getTrueRemoteAddress(), $allowedIps, true);
    }

    /**
     * The real TCP peer (REMOTE_ADDR), never the X-Forwarded-For/X-Real-IP/Client-IP headers:
     * on Mage-OS the Magento\Framework\HTTP\PhpEnvironment\RemoteAddress service trusts
     * those headers by default (core app/etc/di.xml), so an external client can
     * spoof the IP and bypass the allowlist. If the site is behind a real reverse proxy,
     * REMOTE_ADDR will be the proxy's IP: it is the only value that cannot be spoofed by the caller.
     */
    private function getTrueRemoteAddress(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
