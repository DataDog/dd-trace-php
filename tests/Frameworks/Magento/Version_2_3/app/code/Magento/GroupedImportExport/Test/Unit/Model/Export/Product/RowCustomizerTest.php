<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GroupedImportExport\Test\Unit\Model\Export\Product;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * Class RowCustomizerTest
 */
class RowCustomizerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\GroupedImportExport\Model\Export\RowCustomizer|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $rowCustomizerMock;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->rowCustomizerMock = $this->objectManagerHelper->getObject(
            \Magento\GroupedImportExport\Model\Export\RowCustomizer::class
        );
    }

    /**
     * Test addHeaderColumns()
     */
    public function testAddHeaderColumns()
    {
        $productData = [0 => 'sku'];
        $expectedData = [
            0 => 'sku',
            1 => 'associated_skus'
        ];
        $this->assertEquals($expectedData, $this->rowCustomizerMock->addHeaderColumns($productData));
    }
}
