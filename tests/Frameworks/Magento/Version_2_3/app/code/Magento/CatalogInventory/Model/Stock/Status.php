<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Model\Stock;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Model\AbstractExtensibleModel;

/**
 * CatalogInventory Stock Status
 */
class Status extends AbstractExtensibleModel implements StockStatusInterface
{
    /**#@+
     * Field name
     */
    const KEY_PRODUCT_ID = 'product_id';
    const KEY_WEBSITE_ID = 'website_id';
    const KEY_STOCK_ID = 'stock_id';
    const KEY_QTY = 'qty';
    const KEY_STOCK_STATUS = 'stock_status';
    /**#@-*/

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param StockRegistryInterface $stockRegistry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        StockRegistryInterface $stockRegistry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $resource,
            $resourceCollection,
            $data
        );
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * Init resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\CatalogInventory\Model\ResourceModel\Stock\Status::class);
    }

    //@codeCoverageIgnoreStart

    /**
     * Retrieve  product ID
     *
     * @return int
     */
    public function getProductId()
    {
        return $this->getData(self::KEY_PRODUCT_ID);
    }

    /**
     * Retrieve website ID
     *
     * @return int
     */
    public function getWebsiteId()
    {
        return $this->getData(self::KEY_WEBSITE_ID);
    }

    /**
     * Retrieve stock ID
     *
     * @return int
     */
    public function getStockId()
    {
        return $this->getData(self::KEY_STOCK_ID);
    }

    /**
     * Retrieve qty
     *
     * @return int
     */
    public function getQty()
    {
        return $this->getData(self::KEY_QTY);
    }

    /**
     * Retrieve stock status
     *
     * @return int
     */
    public function getStockStatus(): int
    {
        return (int)$this->getData(self::KEY_STOCK_STATUS);
    }

    //@codeCoverageIgnoreEnd

    /**
     * Retrieve stock item
     *
     * @return StockItemInterface
     */
    public function getStockItem()
    {
        return $this->stockRegistry->getStockItem($this->getProductId(), $this->getWebsiteId());
    }

    //@codeCoverageIgnoreStart

    /**
     * Set product ID
     *
     * @param int $productId
     * @return $this
     */
    public function setProductId($productId)
    {
        return $this->setData(self::KEY_PRODUCT_ID, $productId);
    }

    /**
     * Set web website ID
     *
     * @param int $websiteId
     * @return $this
     */
    public function setWebsiteId($websiteId)
    {
        return $this->setData(self::KEY_WEBSITE_ID, $websiteId);
    }

    /**
     * Set stock ID
     *
     * @param int $stockId
     * @return $this
     */
    public function setStockId($stockId)
    {
        return $this->setData(self::KEY_STOCK_ID, $stockId);
    }

    /**
     * Set qty
     *
     * @param int $qty
     * @return $this
     */
    public function setQty($qty)
    {
        return $this->setData(self::KEY_QTY, $qty);
    }

    /**
     * Set stock status
     *
     * @param int $stockStatus
     * @return $this
     */
    public function setStockStatus($stockStatus)
    {
        return $this->setData(self::KEY_STOCK_STATUS, $stockStatus);
    }

    /**
     * Retrieve existing extension attributes object or create a new one.
     *
     * @return \Magento\CatalogInventory\Api\Data\StockStatusExtensionInterface|null
     */
    public function getExtensionAttributes()
    {
        return $this->_getExtensionAttributes();
    }

    /**
     * Set an extension attributes object.
     *
     * @param \Magento\CatalogInventory\Api\Data\StockStatusExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(
        \Magento\CatalogInventory\Api\Data\StockStatusExtensionInterface $extensionAttributes
    ) {
        return $this->_setExtensionAttributes($extensionAttributes);
    }

    //@codeCoverageIgnoreEnd
}
