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

    /** Stati dai quali il documento non evolve più da solo */
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
            self::PENDING => __('In attesa'),
            self::GENERATED => __('Generato'),
            self::SENT => __('Inviato in firma'),
            self::SIGNED => __('Firmato'),
            self::DECLINED => __('Rifiutato'),
            self::EXPIRED => __('Scaduto'),
            self::ERROR => __('Errore'),
            self::CANCELED => __('Annullato'),
        ];
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATES, true);
    }
}
