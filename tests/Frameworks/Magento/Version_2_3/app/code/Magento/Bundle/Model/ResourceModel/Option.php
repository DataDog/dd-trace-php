<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\EntityManager\EntityManager;

/**
 * Bundle Option Resource Model
 */
class Option extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * @var \Magento\Bundle\Model\Option\Validator
     */
    private $validator;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Bundle\Model\Option\Validator $validator
     * @param string $connectionName
     * @param EntityManager|null $entityManager
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Bundle\Model\Option\Validator $validator,
        $connectionName = null,
        EntityManager $entityManager = null
    ) {
        parent::__construct($context, $connectionName);
        $this->validator = $validator;

        $this->entityManager = $entityManager
            ?: ObjectManager::getInstance()->get(EntityManager::class);
    }

    /**
     * Initialize connection and define resource
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_product_bundle_option', 'option_id');
    }

    /**
     * @param int $optionId
     * @return int
     */
    public function removeOptionSelections($optionId)
    {
        return $this->getConnection()->delete(
            $this->getTable('catalog_product_bundle_selection'),
            ['option_id =?' => $optionId]
        );
    }

    /**
     * After save process
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterSave(\Magento\Framework\Model\AbstractModel $object)
    {
        parent::_afterSave($object);

        $condition = [
            'option_id = ?' => $object->getId(),
            'store_id = ? OR store_id = 0' => $object->getStoreId(),
            'parent_product_id = ?' => $object->getParentId()
        ];

        $connection = $this->getConnection();
        $connection->delete($this->getTable('catalog_product_bundle_option_value'), $condition);

        $data = new \Magento\Framework\DataObject();
        $data->setOptionId($object->getId())
            ->setStoreId($object->getStoreId())
            ->setParentProductId($object->getParentId())
            ->setTitle($object->getTitle());

        $connection->insert($this->getTable('catalog_product_bundle_option_value'), $data->getData());

        /**
         * also saving default fallback value
         */
        if (0 !== (int)$object->getStoreId()) {
            $data->setStoreId(0)->setTitle($object->getDefaultTitle());
            $connection->insert($this->getTable('catalog_product_bundle_option_value'), $data->getData());
        }

        return $this;
    }

    /**
     * After delete process
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterDelete(\Magento\Framework\Model\AbstractModel $object)
    {
        parent::_afterDelete($object);

        $this->getConnection()
            ->delete(
                $this->getTable('catalog_product_bundle_option_value'),
                [
                    'option_id = ?' => $object->getId(),
                    'parent_product_id = ?' => $object->getParentId()
                ]
            );

        return $this;
    }

    /**
     * Retrieve options searchable data
     *
     * @param int $productId
     * @param int $storeId
     * @return array
     */
    public function getSearchableData($productId, $storeId)
    {
        $connection = $this->getConnection();

        $title = $connection->getCheckSql(
            'option_title_store.title IS NOT NULL',
            'option_title_store.title',
            'option_title_default.title'
        );
        $bind = ['store_id' => $storeId, 'product_id' => $productId];
        $linkField = $this->getMetadataPool()->getMetadata(ProductInterface::class)->getLinkField();
        $select = $connection->select()
            ->from(
                ['opt' => $this->getMainTable()],
                []
            )
            ->join(
                ['option_title_default' => $this->getTable('catalog_product_bundle_option_value')],
                'option_title_default.option_id = opt.option_id AND option_title_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['option_title_store' => $this->getTable('catalog_product_bundle_option_value')],
                'option_title_store.option_id = opt.option_id AND option_title_store.store_id = :store_id',
                ['title' => $title]
            )
            ->join(
                ['e' => $this->getTable('catalog_product_entity')],
                "e.$linkField = opt.parent_id",
                []
            )
            ->where(
                'e.entity_id=:product_id'
            );
        if (!($searchData = $connection->fetchCol($select, $bind))) {
            $searchData = [];
        }

        return $searchData;
    }

    /**
     * {@inheritdoc}
     */
    public function getValidationRulesBeforeSave()
    {
        return $this->validator;
    }

    /**
     * Get MetadataPool instance
     * @return MetadataPool
     */
    private function getMetadataPool()
    {
        if (!$this->metadataPool) {
            $this->metadataPool = ObjectManager::getInstance()->get(MetadataPool::class);
        }
        return $this->metadataPool;
    }

    /**
     * {@inheritdoc}
     */
    public function save(\Magento\Framework\Model\AbstractModel $object)
    {
        $this->entityManager->save($object);

        return $this;
    }
}
