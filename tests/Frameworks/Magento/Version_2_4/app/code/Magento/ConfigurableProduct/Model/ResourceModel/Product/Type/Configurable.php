<?php
/**
 * Configurable product type resource model
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Model\ResourceModel\Product\Type;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Api\Data\OptionInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Catalog\Model\ResourceModel\Product\Relation as ProductRelation;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\ConfigurableProduct\Model\AttributeOptionProviderInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Attribute\OptionProvider;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Configurable product resource model.
 */
class Configurable extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Catalog product relation
     *
     * @var ProductRelation
     */
    protected $catalogProductRelation;

    /**
     * @var AttributeOptionProviderInterface
     */
    private $attributeOptionProvider;

    /**
     * @var ScopeResolverInterface
     */
    private $scopeResolver;

    /**
     * @var OptionProvider
     */
    private $optionProvider;

    /**
     * @param DbContext $context
     * @param ProductRelation $catalogProductRelation
     * @param string $connectionName
     * @param ScopeResolverInterface $scopeResolver
     * @param AttributeOptionProviderInterface $attributeOptionProvider
     * @param OptionProvider $optionProvider
     */
    public function __construct(
        DbContext $context,
        ProductRelation $catalogProductRelation,
        $connectionName = null,
        ScopeResolverInterface $scopeResolver = null,
        AttributeOptionProviderInterface $attributeOptionProvider = null,
        OptionProvider $optionProvider = null
    ) {
        $this->catalogProductRelation = $catalogProductRelation;
        $this->scopeResolver = $scopeResolver;
        $this->attributeOptionProvider = $attributeOptionProvider
            ?: ObjectManager::getInstance()->get(AttributeOptionProviderInterface::class);
        $this->optionProvider = $optionProvider ?: ObjectManager::getInstance()->get(OptionProvider::class);
        parent::__construct($context, $connectionName);
    }

    /**
     * Init resource
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_product_super_link', 'link_id');
    }

    /**
     * Get product entity id by product attribute
     *
     * @param OptionInterface $option
     * @return int
     */
    public function getEntityIdByAttribute(OptionInterface $option)
    {
        $select = $this->getConnection()->select()->from(
            ['e' => $this->getTable('catalog_product_entity')],
            ['e.entity_id']
        )->where(
            'e.' . $this->optionProvider->getProductEntityLinkField() . '=?',
            $option->getProductId()
        )->limit(1);

        return (int) $this->getConnection()->fetchOne($select);
    }

    /**
     * Save configurable product relations
     *
     * @param ProductModel $mainProduct the parent id
     * @param array $productIds the children id array
     * @return $this
     */
    public function saveProducts($mainProduct, array $productIds)
    {
        if (!$mainProduct instanceof ProductInterface) {
            return $this;
        }

        $productId = $mainProduct->getData($this->optionProvider->getProductEntityLinkField());
        $select = $this->getConnection()->select()->from(
            ['t' => $this->getMainTable()],
            ['product_id']
        )->where(
            't.parent_id = ?',
            $productId
        );

        $existingProductIds = $this->getConnection()->fetchCol($select);
        $insertProductIds = array_diff($productIds, $existingProductIds);
        $deleteProductIds = array_diff($existingProductIds, $productIds);

        if (!empty($insertProductIds)) {
            $insertData = [];
            foreach ($insertProductIds as $id) {
                $insertData[] = ['product_id' => (int) $id, 'parent_id' => (int) $productId];
            }
            $this->getConnection()->insertMultiple(
                $this->getMainTable(),
                $insertData
            );
        }

        if (!empty($deleteProductIds)) {
            $where = ['parent_id = ?' => $productId, 'product_id IN (?)' => $deleteProductIds];
            $this->getConnection()->delete($this->getMainTable(), $where);
        }

        // configurable product relations should be added to relation table
        $this->catalogProductRelation->processRelations($productId, $productIds);

        return $this;
    }

    /**
     * Retrieve Required children ids
     * Return grouped array, ex array(
     *   group => array(ids)
     * )
     *
     * @param int|array $parentId
     * @param bool $required
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getChildrenIds($parentId, $required = true)
    {
        $select = $this->getConnection()->select()->from(
            ['l' => $this->getMainTable()],
            ['product_id', 'parent_id']
        )->join(
            ['p' => $this->getTable('catalog_product_entity')],
            'p.' . $this->optionProvider->getProductEntityLinkField() . ' = l.parent_id',
            []
        )->join(
            ['e' => $this->getTable('catalog_product_entity')],
            'e.entity_id = l.product_id AND e.required_options = 0',
            []
        )->where(
            'p.entity_id IN (?)',
            $parentId,
            \Zend_Db::INT_TYPE
        );

        $childrenIds = [
            0 => array_column(
                $this->getConnection()->fetchAll($select),
                'product_id',
                'product_id'
            )
        ];

        return $childrenIds;
    }

    /**
     * Retrieve parent ids array by required child
     *
     * @param int|array $childId
     * @return string[]
     */
    public function getParentIdsByChild($childId)
    {
        $select = $this->getConnection()
            ->select()
            ->from(['l' => $this->getMainTable()], [])
            ->join(
                ['e' => $this->getTable('catalog_product_entity')],
                'e.' . $this->optionProvider->getProductEntityLinkField() . ' = l.parent_id',
                ['e.entity_id']
            )->where('l.product_id IN(?)', $childId, \Zend_Db::INT_TYPE);
        $parentIds = $this->getConnection()->fetchCol($select);

        return $parentIds;
    }

    /**
     * Collect product options with values according to the product instance and attributes, that were received
     *
     * @param ProductModel $product
     * @param array $attributes
     * @return array
     */
    public function getConfigurableOptions($product, $attributes)
    {
        $attributesOptionsData = [];
        $productId = $product->getData($this->optionProvider->getProductEntityLinkField());
        foreach ($attributes as $superAttribute) {
            $attributeId = $superAttribute->getAttributeId();
            $attributesOptionsData[$attributeId] = $this->getAttributeOptions($superAttribute, $productId);
        }
        return $attributesOptionsData;
    }

    /**
     * Load options for attribute
     *
     * @param AbstractAttribute $superAttribute
     * @param int $productId
     * @return array
     */
    public function getAttributeOptions(AbstractAttribute $superAttribute, $productId)
    {
        return $this->attributeOptionProvider->getAttributeOptions($superAttribute, $productId);
    }
}
