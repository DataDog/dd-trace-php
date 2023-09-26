<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\ResourceModel\Product\Option;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Catalog product options collection
 *
 * @api
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @since 100.0.2
 */
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * @var JoinProcessorInterface
     * @since 101.0.0
     */
    protected $joinProcessor;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     * @since 101.0.0
     */
    protected $metadataPool;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Option value factory
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\Option\Value\CollectionFactory
     */
    protected $_optionValueCollectionFactory;

    /**
     * @param \Magento\Framework\Data\Collection\EntityFactory $entityFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Catalog\Model\ResourceModel\Product\Option\Value\CollectionFactory $optionValueCollectionFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource
     * @param MetadataPool $metadataPool
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Catalog\Model\ResourceModel\Product\Option\Value\CollectionFactory $optionValueCollectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null,
        MetadataPool $metadataPool = null
    ) {
        $this->_optionValueCollectionFactory = $optionValueCollectionFactory;
        $this->_storeManager = $storeManager;
        $this->metadataPool = $metadataPool ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\EntityManager\MetadataPool::class);
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    /**
     * Resource initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Magento\Catalog\Model\Product\Option::class,
            \Magento\Catalog\Model\ResourceModel\Product\Option::class
        );
    }

    /**
     * Adds title, price & price_type attributes to result
     *
     * @param int $storeId
     * @return $this
     */
    public function getOptions($storeId)
    {
        $this->addPriceToResult($storeId)->addTitleToResult($storeId);

        return $this;
    }

    /**
     * Add title to result
     *
     * @param int $storeId
     * @return $this
     */
    public function addTitleToResult($storeId)
    {
        $productOptionTitleTable = $this->getTable('catalog_product_option_title');
        $connection = $this->getConnection();
        $titleExpr = $connection->getCheckSql(
            'store_option_title.title IS NULL',
            'default_option_title.title',
            'store_option_title.title'
        );

        $this->getSelect()->join(
            ['default_option_title' => $productOptionTitleTable],
            'default_option_title.option_id = main_table.option_id',
            ['default_title' => 'title']
        )->joinLeft(
            ['store_option_title' => $productOptionTitleTable],
            'store_option_title.option_id = main_table.option_id AND ' . $connection->quoteInto(
                'store_option_title.store_id = ?',
                $storeId
            ),
            ['store_title' => 'title', 'title' => $titleExpr]
        )->where(
            'default_option_title.store_id = ?',
            \Magento\Store\Model\Store::DEFAULT_STORE_ID
        );

        return $this;
    }

    /**
     * Add price to result
     *
     * @param int $storeId
     * @return $this
     */
    public function addPriceToResult($storeId)
    {
        $productOptionPriceTable = $this->getTable('catalog_product_option_price');
        $connection = $this->getConnection();
        $priceExpr = $connection->getCheckSql(
            'store_option_price.price IS NULL',
            'default_option_price.price',
            'store_option_price.price'
        );
        $priceTypeExpr = $connection->getCheckSql(
            'store_option_price.price_type IS NULL',
            'default_option_price.price_type',
            'store_option_price.price_type'
        );

        $this->getSelect()->joinLeft(
            ['default_option_price' => $productOptionPriceTable],
            'default_option_price.option_id = main_table.option_id AND ' . $connection->quoteInto(
                'default_option_price.store_id = ?',
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            ),
            ['default_price' => 'price', 'default_price_type' => 'price_type']
        )->joinLeft(
            ['store_option_price' => $productOptionPriceTable],
            'store_option_price.option_id = main_table.option_id AND ' . $connection->quoteInto(
                'store_option_price.store_id = ?',
                $storeId
            ),
            [
                'store_price' => 'price',
                'store_price_type' => 'price_type',
                'price' => $priceExpr,
                'price_type' => $priceTypeExpr
            ]
        );

        return $this;
    }

    /**
     * Add value to result
     *
     * @param int $storeId
     * @return $this
     */
    public function addValuesToResult($storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->_storeManager->getStore()->getId();
        }
        $optionIds = [];
        foreach ($this as $option) {
            $optionIds[] = $option->getId();
        }
        if (!empty($optionIds)) {
            /** @var \Magento\Catalog\Model\ResourceModel\Product\Option\Value\Collection $values */
            $values = $this->_optionValueCollectionFactory->create();
            $values->addTitleToResult(
                $storeId
            )->addPriceToResult(
                $storeId
            )->addOptionToFilter(
                $optionIds
            )->setOrder(
                'sort_order',
                self::SORT_ORDER_ASC
            )->setOrder(
                'title',
                self::SORT_ORDER_ASC
            );

            foreach ($values as $value) {
                $optionId = $value->getOptionId();
                if ($this->getItemById($optionId)) {
                    $this->getItemById($optionId)->addValue($value);
                    $value->setOption($this->getItemById($optionId));
                }
            }
        }

        return $this;
    }

    /**
     * Add product_id filter to select
     *
     * @param array|\Magento\Catalog\Model\Product|int $product
     * @return $this
     */
    public function addProductToFilter($product)
    {
        if (empty($product)) {
            $this->addFieldToFilter('product_id', '');
        } elseif (is_array($product)) {
            $this->addFieldToFilter('product_id', ['in' => $product]);
        } elseif ($product instanceof \Magento\Catalog\Model\Product) {
            $this->addFieldToFilter('product_id', $product->getId());
        } else {
            $this->addFieldToFilter('product_id', $product);
        }

        return $this;
    }

    /**
     * @return void
     * @throws \Exception
     * @since 101.0.0
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->join(
            ['cpe' => $this->getTable('catalog_product_entity')],
            sprintf(
                'cpe.%s = main_table.product_id',
                $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField()
            ),
            []
        );
    }

    /**
     * @param int $productId
     * @param int $storeId
     * @param bool $requiredOnly
     * @return \Magento\Catalog\Api\Data\ProductCustomOptionInterface[]
     * @since 101.0.0
     */
    public function getProductOptions($productId, $storeId, $requiredOnly = false)
    {
        $collection = $this->addFieldToFilter(
            'cpe.entity_id',
            $productId
        )->addTitleToResult(
            $storeId
        )->addPriceToResult(
            $storeId
        )->setOrder(
            'sort_order',
            'asc'
        )->setOrder(
            'title',
            'asc'
        );
        if ($requiredOnly) {
            $collection->addRequiredFilter();
        }
        $collection->addValuesToResult($storeId);
        $this->getJoinProcessor()->process($collection);
        return $collection->getItems();
    }

    /**
     * Add is_required filter to select
     *
     * @param bool $required
     * @return $this
     */
    public function addRequiredFilter($required = true)
    {
        $this->addFieldToFilter('main_table.is_require', (int)$required);
        return $this;
    }

    /**
     * Add filtering by option ids
     *
     * @param string|array $optionIds
     * @return $this
     */
    public function addIdsToFilter($optionIds)
    {
        $this->addFieldToFilter('main_table.option_id', $optionIds);
        return $this;
    }

    /**
     * Call of protected method reset
     *
     * @return $this
     */
    public function reset()
    {
        return $this->_reset();
    }

    /**
     * @return JoinProcessorInterface
     */
    private function getJoinProcessor()
    {
        if (null === $this->joinProcessor) {
            $this->joinProcessor = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface::class);
        }
        return $this->joinProcessor;
    }
}
