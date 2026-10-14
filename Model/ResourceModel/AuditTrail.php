<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AuditTrail extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_document_audit_trail';

    /**
     * document_id is a manually-assigned FK-as-PK (it is the signed
     * document's own id, set by the caller before the first save), not an
     * auto-increment column. Without this flag, AbstractDb's default
     * auto-increment assumption makes isObjectNotNew() treat any object
     * with a non-empty id as "not new", so the very first save() issues an
     * UPDATE (affecting zero rows) instead of an INSERT and the row is
     * silently never created. Do not remove this "to clean it up" -
     * document_id really is never auto-generated here.
     */
    protected $_isPkAutoIncrement = false;

    /**
     * Paired with $_isPkAutoIncrement = false above: AbstractDb's default
     * isObjectNotNew() check only looks at whether the object already has a
     * (non-empty) id, which is true here from the moment the caller sets
     * document_id on a brand-new object, well before the first save. With
     * $_useIsObjectNew = true, AbstractDb instead trusts the model's own
     * isObjectNew() flag, which callers explicitly set to true right after
     * AuditTrailFactory::create() (see AuditTrailRecorder and
     * AuditTrailFetchConsumer), so a brand-new row correctly INSERTs.
     */
    protected $_useIsObjectNew = true;

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'document_id');
    }
}
