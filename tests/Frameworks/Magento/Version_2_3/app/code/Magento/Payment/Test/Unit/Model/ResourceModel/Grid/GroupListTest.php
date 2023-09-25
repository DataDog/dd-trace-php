<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Payment\Test\Unit\Model\ResourceModel\Grid;

class GroupListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Payment\Model\ResourceModel\Grid\GroupsList
     */
    protected $groupArrayModel;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $helperMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(\Magento\Payment\Helper\Data::class);
        $this->groupArrayModel = new \Magento\Payment\Model\ResourceModel\Grid\GroupList($this->helperMock);
    }

    public function testToOptionArray()
    {
        $this->helperMock
            ->expects($this->once())
            ->method('getPaymentMethodList')
            ->with(true, true, true)
            ->willReturn(['group data']);
        $this->assertEquals(['group data'], $this->groupArrayModel->toOptionArray());
    }
}
