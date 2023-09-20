<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Test\Unit\Model\Sales\Order;

use \Magento\Tax\Model\Sales\Order\TaxManagement;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxManagementTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var TaxManagement
     */
    private $taxManagement;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $taxItemResourceMock;

    /**
     * @var \Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterface
     */
    protected $appliedTaxDataObject;

    /**
     * @var \Magento\Tax\Model\Sales\Order\Details
     */
    protected $orderTaxDetailsDataObject;

    protected function setUp(): void
    {
        $this->orderMock = $this->createPartialMock(\Magento\Sales\Model\Order::class, ['load']);

        $methods = ['create'];
        $orderFactoryMock = $this->createPartialMock(\Magento\Sales\Model\OrderFactory::class, $methods);
        $orderFactoryMock->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($this->orderMock);

        $className = \Magento\Sales\Model\ResourceModel\Order\Tax\Item::class;
        $this->taxItemResourceMock = $this->createPartialMock($className, ['getTaxItemsByOrderId']);

        $className = \Magento\Sales\Model\ResourceModel\Order\Tax\ItemFactory::class;
        $taxItemFactoryMock = $this->createPartialMock($className, $methods, []);
        $taxItemFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->taxItemResourceMock);

        $objectManager = new ObjectManager($this);
        $this->appliedTaxDataObject = $objectManager->getObject(\Magento\Tax\Model\Sales\Order\Tax::class);

        $className = \Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterfaceFactory::class;
        $appliedTaxDataObjectFactoryMock = $this->createPartialMock($className, $methods);
        $appliedTaxDataObjectFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->appliedTaxDataObject);

        $itemDataObject = $objectManager->getObject(\Magento\Sales\Model\Order\Tax\Item::class);

        $className = \Magento\Tax\Api\Data\OrderTaxDetailsItemInterfaceFactory::class;
        $itemDataObjectFactoryMock = $this->createPartialMock($className, $methods);
        $itemDataObjectFactoryMock->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($itemDataObject);

        $this->orderTaxDetailsDataObject = $objectManager->getObject(\Magento\Tax\Model\Sales\Order\Details::class);

        $className = \Magento\Tax\Api\Data\OrderTaxDetailsInterfaceFactory::class;
        $orderTaxDetailsDataObjectFactoryMock = $this->createPartialMock($className, $methods);
        $orderTaxDetailsDataObjectFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->orderTaxDetailsDataObject);

        $this->taxManagement = $objectManager->getObject(
            \Magento\Tax\Model\Sales\Order\TaxManagement::class,
            [
                'orderFactory' => $orderFactoryMock,
                'orderItemTaxFactory' => $taxItemFactoryMock,
                'orderTaxDetailsDataObjectFactory' => $orderTaxDetailsDataObjectFactoryMock,
                'itemDataObjectFactory' => $itemDataObjectFactoryMock,
                'appliedTaxDataObjectFactory' => $appliedTaxDataObjectFactoryMock
            ]
        );
    }

    /**
     * @param array $orderItemAppliedTaxes
     * @param array $expected
     * @return void
     * @dataProvider getOrderTaxDetailsDataProvider
     */
    public function testGetOrderTaxDetails($orderItemAppliedTaxes, $expected)
    {
        $orderId = 1;
        $this->orderMock->expects($this->once())
            ->method('load')
            ->with($orderId)
            ->willReturnSelf();
        $this->taxItemResourceMock->expects($this->once())
            ->method('getTaxItemsByOrderId')
            ->with($orderId)
            ->willReturn($orderItemAppliedTaxes);

        $this->assertEquals($this->orderTaxDetailsDataObject, $this->taxManagement->getOrderTaxDetails($orderId));

        $this->assertEquals($expected['code'], $this->appliedTaxDataObject->getCode());
        $this->assertEquals($expected['title'], $this->appliedTaxDataObject->getTitle());
        $this->assertEquals($expected['tax_percent'], $this->appliedTaxDataObject->getPercent());
        $this->assertEquals($expected['real_amount'], $this->appliedTaxDataObject->getAmount());
        $this->assertEquals($expected['real_base_amount'], $this->appliedTaxDataObject->getBaseAmount());
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getOrderTaxDetailsDataProvider()
    {
        $data = [
            'one_item' => [
                'orderItemAppliedTaxes' => [
                    [
                        'item_id' => 53,
                        'taxable_item_type' => 'product',
                        'associated_item_id' => null,
                        'code' => 'US-CA-*-Rate 1',
                        'title' => 'US-CA-*-Rate 1',
                        'tax_percent' => '8.25',
                        'real_amount' => '6.1889',
                        'real_base_amount' => '12.3779',
                    ],
                ],
                'expected' => [
                    'code' => 'US-CA-*-Rate 1',
                    'title' => 'US-CA-*-Rate 1',
                    'tax_percent' => '8.25',
                    'real_amount' => '6.1889',
                    'real_base_amount' => '12.3779',
                ],
            ],

            'weee_item' => [
                'orderItemAppliedTaxes' => [
                    [
                        'item_id' => null,
                        'taxable_item_type' => 'weee',
                        'associated_item_id' => 54,
                        'code' => 'SanJose City Tax',
                        'title' => 'SanJose City Tax',
                        'tax_percent' => '6',
                        'real_amount' => '0.9011',
                        'real_base_amount' => '1.7979',
                    ],
                ],
                'expected' => [
                    'code' => 'SanJose City Tax',
                    'title' => 'SanJose City Tax',
                    'tax_percent' => '6',
                    'real_amount' => '0.9011',
                    'real_base_amount' => '1.7979',
                ],
            ],

            'shipping' => [
                'orderItemAppliedTaxes' => [
                    [
                        'item_id' => null,
                        'taxable_item_type' => 'shipping',
                        'associated_item_id' => null,
                        'code' => 'Shipping',
                        'title' => 'Shipping',
                        'tax_percent' => '21',
                        'real_amount' => '2.6',
                        'real_base_amount' => '5.21',
                    ],
                ],
                'expected' => [
                    'code' => 'Shipping',
                    'title' => 'Shipping',
                    'tax_percent' => '21',
                    'real_amount' => '2.6',
                    'real_base_amount' => '5.21',
                ],
            ],

            'canadian_weee' => [
                'orderItemAppliedTaxes' => [
                    [
                        'item_id' => null,
                        'taxable_item_type' => 'weee',
                        'associated_item_id' => 69,
                        'code' => 'GST',
                        'title' => 'GST',
                        'tax_percent' => '5',
                        'real_amount' => '2.10',
                        'real_base_amount' => '4.10',
                    ],
                    [
                        'item_id' => null,
                        'taxable_item_type' => 'weee',
                        'associated_item_id' => 69,
                        'code' => 'GST',
                        'title' => 'GST',
                        'tax_percent' => '5',
                        'real_amount' => '1.15',
                        'real_base_amount' => '3.10',
                    ],
                ],
                'expected' => [
                    'code' => 'GST',
                    'title' => 'GST',
                    'tax_percent' => '5',
                    'real_amount' => '3.25',
                    'real_base_amount' => '7.20',
                ],
            ],
        ];

        return $data;
    }
}
