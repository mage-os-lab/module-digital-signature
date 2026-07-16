<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;

class PdfJsPolicyCollector implements PolicyCollectorInterface
{
    private const XML_PATH_SOURCE = 'digital_signature/general/pdfjs_source';
    private const XML_PATH_CDN_URL = 'digital_signature/general/pdfjs_cdn_url';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function collect(array $policies = []): array
    {
        $source = $this->scopeConfig->getValue(self::XML_PATH_SOURCE);
        if ($source !== 'cdn') {
            return $policies;
        }

        $cdnUrl = (string)$this->scopeConfig->getValue(self::XML_PATH_CDN_URL);
        if ($cdnUrl === '') {
            return $policies;
        }

        $host = parse_url($cdnUrl, PHP_URL_HOST);
        if (!$host) {
            return $policies;
        }

        $policies[] = new FetchPolicy(
            'script-src',
            $host,
            true,
            true
        );
        $policies[] = new FetchPolicy(
            'worker-src',
            $host,
            true,
            true
        );

        return $policies;
    }
}
