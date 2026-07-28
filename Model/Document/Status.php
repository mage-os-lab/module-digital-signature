<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Document;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public const PENDING = 'pending';
    public const GENERATED = 'generated';
    public const SENT = 'sent';
    public const SIGNED = 'signed';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const ERROR = 'error';
    public const CANCELED = 'canceled';

    /** States from which the document no longer evolves on its own */
    public const FINAL_STATES = [self::SIGNED, self::DECLINED, self::EXPIRED, self::CANCELED];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::getLabels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return array<string, \Magento\Framework\Phrase>
     */
    public static function getLabels(): array
    {
        return [
            self::PENDING => __('Pending'),
            self::GENERATED => __('Generated'),
            self::SENT => __('Sent for signature'),
            self::SIGNED => __('Signed'),
            self::DECLINED => __('Declined'),
            self::EXPIRED => __('Expired'),
            self::ERROR => __('Error'),
            self::CANCELED => __('Canceled'),
        ];
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATES, true);
    }
}
