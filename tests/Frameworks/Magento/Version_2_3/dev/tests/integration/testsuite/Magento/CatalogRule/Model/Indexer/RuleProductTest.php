<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogRule\Model\Indexer;

use Magento\TestFramework\Helper\Bootstrap;

/**
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class RuleProductTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogRule\Model\Indexer\IndexBuilder
     */
    protected $indexBuilder;

    /**
     * @var \Magento\CatalogRule\Model\ResourceModel\Rule
     */
    protected $resourceRule;

    protected function setUp(): void
    {
        $this->indexBuilder = Bootstrap::getObjectManager()->get(
            \Magento\CatalogRule\Model\Indexer\IndexBuilder::class
        );
        $this->resourceRule = Bootstrap::getObjectManager()->get(\Magento\CatalogRule\Model\ResourceModel\Rule::class);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoDataFixtureBeforeTransaction Magento/CatalogRule/_files/attribute.php
     * @magentoDataFixtureBeforeTransaction Magento/CatalogRule/_files/rule_by_attribute.php
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testReindexAfterRuleCreation()
    {
        /** @var \Magento\Catalog\Model\ProductRepository $productRepository */
        $productRepository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Catalog\Model\ProductRepository::class
        );
        $product = $productRepository->get('simple');
        $product->setData('test_attribute', 'test_attribute_value')->save();
        $this->assertFalse($this->resourceRule->getRulePrice(new \DateTime(), 1, 1, $product->getId()));

        // apply all rules
        $this->indexBuilder->reindexFull();

        $this->assertEquals(9.8, $this->resourceRule->getRulePrice(new \DateTime(), 1, 1, $product->getId()));
    }
}
