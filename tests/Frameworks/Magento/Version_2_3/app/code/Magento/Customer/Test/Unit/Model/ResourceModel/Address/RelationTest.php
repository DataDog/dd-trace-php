<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model\ResourceModel\Address;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Customer\Model\Address;

/**
 * Class AddressTest
 */
class RelationTest extends \PHPUnit\Framework\TestCase
{
    /** @var  \Magento\Customer\Model\CustomerFactory | \PHPUnit\Framework\MockObject\MockObject */
    protected $customerFactoryMock;

    /** @var  \Magento\Customer\Model\ResourceModel\Address\Relation */
    protected $relation;

    protected function setUp(): void
    {
        $this->customerFactoryMock = $this->createPartialMock(
            \Magento\Customer\Model\CustomerFactory::class,
            ['create']
        );
        $this->relation = (new ObjectManagerHelper($this))->getObject(
            \Magento\Customer\Model\ResourceModel\Address\Relation::class,
            [
                'customerFactory' => $this->customerFactoryMock
            ]
        );
    }

    /**
     * @param $addressId
     * @param $isDefaultBilling
     * @param $isDefaultShipping
     * @dataProvider getRelationDataProvider
     */
    public function testProcessRelation($addressId, $isDefaultBilling, $isDefaultShipping)
    {
        $addressModel = $this->createPartialMock(Address::class, [
                '__wakeup',
                'getId',
                'getEntityTypeId',
                'getIsDefaultBilling',
                'getIsDefaultShipping',
                'hasDataChanges',
                'validateBeforeSave',
                'beforeSave',
                'afterSave',
                'isSaveAllowed',
                'getIsCustomerSaveTransaction'
            ]);
        $customerModel = $this->createPartialMock(
            \Magento\Customer\Model\Customer::class,
            [
                '__wakeup',
                'setDefaultBilling',
                'setDefaultShipping',
                'save',
                'load',
                'getResource',
                'getId',
                'getDefaultShippingAddress',
                'getDefaultBillingAddress'
            ]
        );
        $customerResource = $this->getMockForAbstractClass(
            \Magento\Framework\Model\ResourceModel\Db\AbstractDb::class,
            [],
            '',
            false,
            false,
            true,
            ['getConnection', 'getTable']
        );
        $connectionMock = $this->getMockForAbstractClass(
            \Magento\Framework\DB\Adapter\AdapterInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['update', 'quoteInto']
        );
        $customerModel->expects($this->any())->method('getResource')->willReturn($customerResource);
        $addressModel->expects($this->any())->method('getId')->willReturn($addressId);
        $addressModel->expects($this->any())->method('getIsDefaultShipping')->willReturn($isDefaultShipping);
        $addressModel->expects($this->any())->method('getIsDefaultBilling')->willReturn($isDefaultBilling);
        $addressModel->expects($this->any())->method('getIsCustomerSaveTransaction')->willReturn(false);

        $customerModel->expects($this->any())
             ->method('load')
             ->willReturnSelf();

        $this->customerFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($customerModel);

        if ($addressId && ($isDefaultBilling || $isDefaultShipping)) {
            $customerId = 1;
            $customerResource->expects($this->exactly(2))->method('getConnection')->willReturn($connectionMock);
            $customerModel->expects($this->any())->method('getId')->willReturn(1);
            $conditionSql = "entity_id = $customerId";
            $connectionMock->expects($this->once())->method('quoteInto')
                ->with('entity_id = ?', $customerId)
                ->willReturn($conditionSql);
            $customerResource->expects($this->once())->method('getTable')
                ->with('customer_entity')
                ->willReturn('customer_entity');
            $toUpdate = [];
            if ($isDefaultBilling) {
                $toUpdate['default_billing'] = $addressId;
            }
            if ($isDefaultShipping) {
                $toUpdate['default_shipping'] = $addressId;
            }
            $connectionMock->expects($this->once())->method('update')->with(
                'customer_entity',
                $toUpdate,
                $conditionSql
            );
        }
        $result = $this->relation->processRelation($addressModel);
        $this->assertNull($result);
    }

    /**
     * Data provider for processRelation method
     *
     * @return array
     */
    public function getRelationDataProvider()
    {
        return [
            [null, true, true],
            [1, true, true],
            [1, true, false],
            [1, false, true],
            [1, false, false],
        ];
    }
}
