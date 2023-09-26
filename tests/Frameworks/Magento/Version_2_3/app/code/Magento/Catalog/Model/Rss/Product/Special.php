<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Rss\Product;

/**
 * Class Special
 * @package Magento\Catalog\Model\Rss\Product
 */
class Special
{
    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->productFactory = $productFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * @param int $storeId
     * @param int $customerGroupId
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    public function getProductsCollection($storeId, $customerGroupId)
    {
        $websiteId = $this->storeManager->getStore($storeId)->getWebsiteId();

        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setStoreId($storeId);

        $collection = $product->getResourceCollection()
            ->addPriceDataFieldFilter('%s < %s', ['final_price', 'price'])
            ->addPriceData($customerGroupId, $websiteId)
            ->addAttributeToSelect(
                [
                    'name',
                    'short_description',
                    'description',
                    'price',
                    'thumbnail',
                    'special_price',
                    'special_to_date',
                    'msrp_display_actual_price_type',
                    'msrp',
                ],
                'left'
            )->addAttributeToSort('name', 'asc');

        return $collection;
    }
}
