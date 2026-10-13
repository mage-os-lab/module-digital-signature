<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Start date of a prepaid quota pool: only an ISO date (YYYY-MM-DD) or empty.
 * Anything else would be parsed loosely by the quota calculator and could
 * silently count the provider's whole history against the pool.
 */
class QuotaStartDate extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $this->setData('value', $this->normalize((string)$this->getData('value')));

        return parent::beforeSave();
    }

    /**
     * @param string $value
     * @return string
     * @throws LocalizedException
     */
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new LocalizedException(
                __('The quota start date must be a valid date in the format YYYY-MM-DD.')
            );
        }

        return $value;
    }
}
