<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Test\Unit\Block\Adminhtml\Product\Edit\Button;

use Magento\ConfigurableProduct\Block\Adminhtml\Product\Edit\Button\Save as SaveButton;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\Registry;
use Magento\Catalog\Api\Data\ProductInterface;

class SaveTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var SaveButton
     */
    private $saveButton;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    private $registryMock;

    /**
     * @var ProductInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $productMock;

    protected function setUp(): void
    {
        $this->registryMock = $this->getMockBuilder(Registry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productMock = $this->getMockBuilder(ProductInterface::class)
            ->setMethods(['isReadonly', 'isDuplicable', 'isComposite'])
            ->getMockForAbstractClass();

        $this->registryMock->expects(static::any())
            ->method('registry')
            ->with('current_product')
            ->willReturn($this->productMock);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->saveButton = $this->objectManagerHelper->getObject(
            SaveButton::class,
            ['registry' => $this->registryMock]
        );
    }

    public function testGetButtonData()
    {
        $result = $this->saveButton->getButtonData();

        $this->assertArrayHasKey('data_attribute', $result);
        $this->assertArrayHasKey('options', $result);
    }
}
