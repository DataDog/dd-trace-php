<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Indexer\Product\Price;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\ScopedProductTierPriceManagementInterface;
use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Pricing\Price\TierPrice;
use Magento\Customer\Model\Group;

class SimpleWithOptionsTierPriceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->productCollectionFactory = $this->objectManager->create(CollectionFactory::class);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoDataFixture Magento/Catalog/_files/category_product.php
     */
    public function testTierPrice()
    {
        $tierPriceValue = 9.00;

        $tierPrice = $this->objectManager->create(ProductTierPriceInterfaceFactory::class)
            ->create();
        $tierPrice->setCustomerGroupId(Group::CUST_GROUP_ALL);
        $tierPrice->setQty(1.00);
        $tierPrice->setValue($tierPriceValue);
        $tierPriceManagement = $this->objectManager->create(ScopedProductTierPriceManagementInterface::class);
        $tierPriceManagement->add('simple333', $tierPrice);

        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addIdFilter(333);
        $productCollection->addPriceData();
        $productCollection->load();
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $productCollection->getFirstItem();
        $tierPrice = $product->getPriceInfo()
            ->getPrice(TierPrice::PRICE_CODE)
            ->getValue();

        $this->assertEquals($tierPriceValue, $tierPrice);

        $tierPrice = $product->getTierPrice(1);
        $this->assertEquals($tierPriceValue, $tierPrice);

        $tierPrices = $product->getData('tier_price');
        $this->assertEquals($tierPriceValue, $tierPrices[0]['price']);

        $minPrice = $product->getData('min_price');
        $this->assertEquals($tierPriceValue, $minPrice);
    }
}
