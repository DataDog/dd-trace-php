<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Test\Unit\Plugin\Model\ResourceModel\Attribute;

use Magento\CatalogInventory\Model\ResourceModel\Stock\Status;
use Magento\ConfigurableProduct\Model\ResourceModel\Attribute\OptionSelectBuilder;
use Magento\ConfigurableProduct\Plugin\Model\ResourceModel\Attribute\InStockOptionSelectBuilder;
use Magento\Framework\DB\Select;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InStockOptionSelectBuilderTest extends TestCase
{
    /**
     * @var InStockOptionSelectBuilder
     */
    private $model;

    /**
     * @var ObjectManager
     */
    private $objectManagerHelper;

    /**
     * @var Status|MockObject
     */
    private $stockStatusResourceMock;

    /**
     * @var OptionSelectBuilder
     */
    private $optionSelectBuilderMock;

    /**
     * @var Select|MockObject
     */
    private $selectMock;

    protected function setUp(): void
    {
        $this->stockStatusResourceMock = $this->getMockBuilder(Status::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->selectMock = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->optionSelectBuilderMock = $this->getMockBuilder(OptionSelectBuilder::class)
            ->setMethods([])
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManagerHelper = new ObjectManager($this);
        $this->model = $this->objectManagerHelper->getObject(
            InStockOptionSelectBuilder::class,
            [
                'stockStatusResource' => $this->stockStatusResourceMock,
            ]
        );
    }

    /**
     * Test for method afterGetSelect.
     */
    public function testAfterGetSelect()
    {
        $this->stockStatusResourceMock->expects($this->once())
            ->method('getMainTable')
            ->willReturn('stock_table_name');

        $this->selectMock->expects($this->once())
            ->method('joinInner')
            ->willReturnSelf();
        $this->selectMock->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $this->assertEquals(
            $this->selectMock,
            $this->model->afterGetSelect($this->optionSelectBuilderMock, $this->selectMock)
        );
    }
}
