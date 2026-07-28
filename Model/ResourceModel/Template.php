<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Template extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_template';
    public const FILE_TABLE = 'mageos_digitalsignature_template_file';
    public const PRODUCT_TABLE = 'mageos_digitalsignature_template_product';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'template_id');
    }

    /**
     * PDF path per store view with fallback to default (store_id=0).
     * Returns [effective_store_id, pdf_path] or null if no file.
     */
    public function getPdfPathForStore(int $templateId, int $storeId): ?array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::FILE_TABLE), ['store_id', 'pdf_path'])
            ->where('template_id = ?', $templateId)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('store_id DESC')
            ->limit(1);
        $row = $connection->fetchRow($select);

        return $row ? [(int)$row['store_id'], (string)$row['pdf_path']] : null;
    }

    public function savePdfPath(int $templateId, int $storeId, string $pdfPath): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable(self::FILE_TABLE),
            ['template_id' => $templateId, 'store_id' => $storeId, 'pdf_path' => $pdfPath],
            ['pdf_path']
        );
    }

    public function deletePdfPath(int $templateId, int $storeId): void
    {
        $this->getConnection()->delete(
            $this->getTable(self::FILE_TABLE),
            ['template_id = ?' => $templateId, 'store_id = ?' => $storeId]
        );
    }

    /**
     * Active templates with cart scope: [template_id, trigger_code, is_required].
     *
     * @return array<int, array<string, string|null>>
     */
    public function getActiveCartTemplateRows(): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['template_id', 'trigger_code', 'is_required'])
            ->where('is_active = 1')
            ->where('scope = ?', 'cart');

        return $connection->fetchAll($select);
    }

    /**
     * Active template assignments with product scope for the given products:
     * [template_id, product_id, trigger_code (product override),
     * template_trigger_code, is_required].
     *
     * @param int[] $productIds
     * @return array<int, array<string, string|null>>
     */
    public function getProductAssignmentRows(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                ['tp' => $this->getTable(self::PRODUCT_TABLE)],
                ['template_id', 'product_id', 'trigger_code']
            )
            ->join(
                ['t' => $this->getMainTable()],
                't.template_id = tp.template_id',
                ['template_trigger_code' => 't.trigger_code', 'is_required' => 't.is_required']
            )
            ->where('t.is_active = 1')
            ->where('t.scope = ?', 'product')
            ->where('tp.product_id IN (?)', array_map('intval', $productIds));

        return $connection->fetchAll($select);
    }

    /**
     * @return int[]
     */
    public function getAssignedProductIds(int $templateId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::PRODUCT_TABLE), 'product_id')
            ->where('template_id = ?', $templateId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * @param int[] $productIds
     */
    public function saveAssignedProductIds(int $templateId, array $productIds): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable(self::PRODUCT_TABLE);
        $productIds = array_unique(array_map('intval', $productIds));
        $current = $this->getAssignedProductIds($templateId);

        $toDelete = array_diff($current, $productIds);
        if ($toDelete) {
            $connection->delete($table, [
                'template_id = ?' => $templateId,
                'product_id IN (?)' => $toDelete,
            ]);
        }

        $toInsert = array_diff($productIds, $current);
        if ($toInsert) {
            $rows = [];
            foreach ($toInsert as $productId) {
                $rows[] = ['template_id' => $templateId, 'product_id' => $productId];
            }
            $connection->insertMultiple($table, $rows);
        }
    }
}
