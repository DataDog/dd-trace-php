<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Model\ResourceModel\Product\Lowstock;

/**
 * Class CollectionTest
 */
class CollectionTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var \Magento\Reports\Model\ResourceModel\Product\Lowstock\Collection
     */
    private $collection;

    protected function setUp(): void
    {
        /**
         * @var  \Magento\Reports\Model\ResourceModel\Product\Lowstock\Collection
         */
        $this->collection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Reports\Model\ResourceModel\Product\Lowstock\Collection::class
        );
    }

    /**
     * Assert that filterByProductType method throws LocalizedException if not String or Array is passed to it
     *
     */
    public function testFilterByProductTypeException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->collection->filterByProductType(100);
    }

    /**
     * Assert that String argument passed to filterByProductType method is correctly passed to attribute adder
     *
     */
    public function testFilterByProductTypeString()
    {
        $this->collection->filterByProductType('simple');
        $whereParts = $this->collection->getSelect()->getPart(\Magento\Framework\DB\Select::WHERE);
        $this->assertStringContainsString('simple', $whereParts[0]);
    }

    /**
     * Assert that Array argument passed to filterByProductType method is correctly passed to attribute adder
     *
     */
    public function testFilterByProductTypeArray()
    {
        $this->collection->filterByProductType(['simple', 'configurable']);
        $whereParts = $this->collection->getSelect()->getPart(\Magento\Framework\DB\Select::WHERE);

        $this->assertThat(
            $whereParts[0],
            $this->logicalAnd(
                $this->stringContains('simple'),
                $this->stringContains('configurable')
            )
        );
    }
}
