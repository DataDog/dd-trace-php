<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Test\Unit\Model\Adminhtml\Stock;

class ItemTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogInventory\Model\Adminhtml\Stock\Item|PHPUnit\Framework\MockObject\MockObject
     */
    protected $_model;

    /**
     * setUp
     */
    protected function setUp(): void
    {
        $resourceMock = $this->createPartialMock(
            \Magento\Framework\Model\ResourceModel\AbstractResource::class,
            ['_construct', 'getConnection', 'getIdFieldName']
        );
        $objectHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $groupManagement = $this->getMockBuilder(\Magento\Customer\Api\GroupManagementInterface::class)
            ->setMethods(['getAllCustomersGroup'])
            ->getMockForAbstractClass();

        $allGroup = $this->getMockBuilder(\Magento\Customer\Api\Data\GroupInterface::class)
            ->setMethods(['getId'])
            ->getMockForAbstractClass();

        $allGroup->expects($this->any())
            ->method('getId')
            ->willReturn(32000);

        $groupManagement->expects($this->any())
            ->method('getAllCustomersGroup')
            ->willReturn($allGroup);

        $this->_model = $objectHelper->getObject(
            \Magento\CatalogInventory\Model\Adminhtml\Stock\Item::class,
            [
                'resource' => $resourceMock,
                'groupManagement' => $groupManagement
            ]
        );
    }

    public function testGetCustomerGroupId()
    {
        $this->_model->setCustomerGroupId(null);
        $this->assertEquals(32000, $this->_model->getCustomerGroupId());
        $this->_model->setCustomerGroupId(2);
        $this->assertEquals(2, $this->_model->getCustomerGroupId());
    }

    public function testGetIdentities()
    {
        $this->_model->setProductId(1);
        $this->assertEquals(['cat_p_1'], $this->_model->getIdentities());
    }
}
