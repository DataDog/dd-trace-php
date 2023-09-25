<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute as ConfigurableAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\Store;

/**
 * Catalog super product attribute resource model.
 */
class Attribute extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Label table name cache
     *
     * @var string
     */
    protected $_labelTable;

    /**
     * Catalog data
     *
     * @var \Magento\Catalog\Helper\Data
     */
    protected $_catalogData = null;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Helper\Data $catalogData
     * @param string $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Helper\Data $catalogData,
        $connectionName = null
    ) {
        $this->_storeManager = $storeManager;
        $this->_catalogData = $catalogData;
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize connection and define tables
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_product_super_attribute', 'product_super_attribute_id');
        $this->_labelTable = $this->getTable('catalog_product_super_attribute_label');
    }

    /**
     * Save Custom labels for Attribute name
     *
     * @param \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute $attribute
     * @return $this
     */
    public function saveLabel($attribute)
    {
        $connection = $this->getConnection();

        $select = $connection->select()->from(
            $this->_labelTable,
            'value_id'
        )->where(
            'product_super_attribute_id = :product_super_attribute_id'
        )->where(
            'store_id = :store_id'
        );
        $bind = [
            'product_super_attribute_id' => (int)$attribute->getId(),
            'store_id' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
        ];
        $valueId = $connection->fetchOne($select, $bind);

        if ($valueId) {
            $connection->insertOnDuplicate(
                $this->_labelTable,
                [
                    'product_super_attribute_id' => (int)$attribute->getId(),
                    'store_id' => (int)$attribute->getStoreId() ?: $this->_storeManager->getStore()->getId(),
                    'use_default' => (int)$attribute->getUseDefault(),
                    'value' => $attribute->getLabel(),
                ],
                ['value', 'use_default']
            );
        } else {
            // if attribute label not exists, always store on default store (0)
            $connection->insert(
                $this->_labelTable,
                [
                    'product_super_attribute_id' => (int)$attribute->getId(),
                    'store_id' => Store::DEFAULT_STORE_ID,
                    'use_default' => (int)$attribute->getUseDefault(),
                    'value' => $attribute->getLabel(),
                ]
            );
        }

        return $this;
    }

    /**
     * Retrieve Used in Configurable Products Attributes
     *
     * @param int $setId The specific attribute set
     * @return array
     */
    public function getUsedAttributes($setId)
    {
        $connection = $this->getConnection();
        $select = $connection->select()->distinct(
            true
        )->from(
            ['e' => $this->getTable('catalog_product_entity')],
            null
        )->join(
            ['a' => $this->getMainTable()],
            'e.entity_id = a.product_id',
            ['attribute_id']
        )->where(
            'e.attribute_set_id = :attribute_set_id'
        )->where(
            'e.type_id = :type_id'
        );

        $bind = [
            'attribute_set_id' => $setId,
            'type_id' => \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE,
        ];

        return $connection->fetchCol($select, $bind);
    }

    /**
     * Get configurable attribute id by product id and attribute id
     *
     * @param \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute $attribute
     * @param mixed $productId
     * @param mixed $attributeId
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getIdByProductIdAndAttributeId($attribute, $productId, $attributeId)
    {
        $select = $this->getConnection()->select()->from(
            $this->getMainTable(),
            $this->getIdFieldName()
        )->where(
            'product_id = ?',
            $productId
        )->where(
            'attribute_id = ?',
            $attributeId
        );
        return $this->getConnection()->fetchOne($select);
    }

    /**
     * Delete configurable attributes by product id
     *
     * @param mixed $productId
     * @return void
     */
    public function deleteAttributesByProductId($productId)
    {
        $select = $this->getConnection()->select()->from(
            $this->getMainTable(),
            $this->getIdFieldName()
        )->where(
            'product_id = ?',
            $productId
        );
        $this->getConnection()->query($this->getConnection()->deleteFromSelect($select, $this->getMainTable()));
    }

    /**
     * @inheritDoc
     */
    protected function _afterLoad(\Magento\Framework\Model\AbstractModel $object)
    {
        parent::_afterLoad($object);
        $this->loadLabel($object);
        return $this;
    }

    /**
     * Load label for configurable attribute
     *
     * @param ConfigurableAttribute $object
     * @return $this
     */
    protected function loadLabel(ConfigurableAttribute $object)
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        $connection = $this->getConnection();
        $useDefaultCheck = $connection
            ->getCheckSql('store.use_default IS NULL', 'def.use_default', 'store.use_default');
        $labelCheck = $connection->getCheckSql('store.value IS NULL', 'def.value', 'store.value');
        $select = $connection
            ->select()
            ->from(['def' => $this->_labelTable])
            ->joinLeft(
                ['store' => $this->_labelTable],
                $connection->quoteInto(
                    'store.product_super_attribute_id = def.product_super_attribute_id AND store.store_id = ?',
                    $storeId
                ),
                ['use_default' => $useDefaultCheck, 'label' => $labelCheck]
            )
            ->where('def.product_super_attribute_id = ?', $object->getId())
            ->where('def.store_id = ?', 0);

        $data = $connection->fetchRow($select);
        $object->setLabel($data['label']);
        $object->setUseDefault($data['use_default']);
        return $this;
    }
}
