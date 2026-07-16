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
 * Callback server-to-server dei provider di firma.
 *
 * La callback è un PING: il payload non viene mai usato; se i controlli
 * passano si accoda un job di polling che legge lo stato dall'API provider.
 * Risposta sempre HTTP 200 uniforme (nessun oracle su validità di id/token).
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
            // Mai esporre dettagli all'esterno; il dettaglio sta nei log
            $this->logger->warning('DigitalSignature: errore callback: ' . $e->getMessage());
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
                'DigitalSignature: callback bloccata da allowlist IP per documento ' . $documentId
            );

            return;
        }
        if (Status::isFinal($document->getStatus())) {
            return;
        }

        // Dedup: al massimo un job di refresh per documento nella finestra TTL
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
     * Il peer TCP reale (REMOTE_ADDR), mai gli header X-Forwarded-For/X-Real-IP/Client-IP:
     * su Mage-OS il servizio Magento\Framework\HTTP\PhpEnvironment\RemoteAddress si fida di
     * quegli header per default (app/etc/di.xml del core), quindi un client esterno può
     * falsificare l'IP e aggirare l'allowlist. Se il sito è dietro un vero reverse proxy,
     * REMOTE_ADDR sarà l'IP del proxy: è l'unico valore non falsificabile dal chiamante.
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
