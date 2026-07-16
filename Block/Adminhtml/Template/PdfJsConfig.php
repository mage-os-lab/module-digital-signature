<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;

class PdfJsConfig extends Template
{
    private const XML_PATH_SOURCE = 'digital_signature/general/pdfjs_source';
    private const XML_PATH_CDN_URL = 'digital_signature/general/pdfjs_cdn_url';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPdfJsUrl(): string
    {
        $source = $this->scopeConfig->getValue(self::XML_PATH_SOURCE);
        if ($source === 'cdn') {
            $cdnUrl = rtrim((string)$this->scopeConfig->getValue(self::XML_PATH_CDN_URL), '/') . '/';
            return $cdnUrl . 'pdf.min.js';
        }
        return $this->getViewFileUrl('MageOS_DigitalSignature::js/lib/pdfjs/pdf.min.js');
    }

    public function getPdfJsWorkerUrl(): string
    {
        $source = $this->scopeConfig->getValue(self::XML_PATH_SOURCE);
        if ($source === 'cdn') {
            $cdnUrl = rtrim((string)$this->scopeConfig->getValue(self::XML_PATH_CDN_URL), '/') . '/';
            return $cdnUrl . 'pdf.worker.min.js';
        }
        return $this->getViewFileUrl('MageOS_DigitalSignature::js/lib/pdfjs/pdf.worker.min.js');
    }

    public function getPreviewTmpUrl(): string
    {
        return $this->getUrl('digitalsignature/template/previewTmp');
    }

    public function getInjectTagUrl(): string
    {
        return $this->getUrl('digitalsignature/template/injectTag');
    }
}
