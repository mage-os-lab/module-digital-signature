<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Righe dinamiche "stato provider → stato interno" in configurazione.
 */
class StatusMapping extends AbstractFieldArray
{
    private ?InternalStatusColumn $internalStatusRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('provider_status', [
            'label' => __('Provider status'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('internal_status', [
            'label' => __('Internal status'),
            'renderer' => $this->getInternalStatusRenderer(),
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add mapping');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $internalStatus = $row->getData('internal_status');
        if ($internalStatus !== null) {
            $optionHash = $this->getInternalStatusRenderer()->calcOptionHash($internalStatus);
            $options['option_' . $optionHash] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    private function getInternalStatusRenderer(): InternalStatusColumn
    {
        if ($this->internalStatusRenderer === null) {
            try {
                $this->internalStatusRenderer = $this->getLayout()->createBlock(
                    InternalStatusColumn::class,
                    '',
                    ['data' => ['is_render_to_js_template' => true]]
                );
            } catch (LocalizedException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        }

        return $this->internalStatusRenderer;
    }
}
