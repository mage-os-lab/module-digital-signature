<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Document;

use MageOS\DigitalSignature\Model\Document\Stats\Aggregator;
use MageOS\DigitalSignature\Model\Document\Stats\PeriodSource;
use MageOS\DigitalSignature\Model\Document\Stats\Summary;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class StatsPanel extends Template
{
    protected $_template = 'MageOS_DigitalSignature::document/stats_panel.phtml';

    public function __construct(
        Context $context,
        private readonly Aggregator $aggregator,
        private readonly PeriodSource $periodSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getActivePeriod(): string
    {
        $period = $this->getRequest()->getParam('stats_period', PeriodSource::PERIOD_30_DAYS);
        $options = $this->periodSource->getOptions();
        return isset($options[$period]) ? $period : PeriodSource::PERIOD_30_DAYS;
    }

    public function getPeriodOptions(): array
    {
        return $this->periodSource->getOptions();
    }

    public function getSummary(): Summary
    {
        $period = $this->getActivePeriod();
        $range = $this->periodSource->getDateRange($period);
        return $this->aggregator->getSummary($range['from'], $range['to']);
    }

    public function getActionUrl(): string
    {
        return $this->getUrl('*/*/*', ['_current' => true]);
    }
}
