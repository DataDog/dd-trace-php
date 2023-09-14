<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Tax\Test\Unit\Observer;

use Magento\Bundle\Model\ResourceModel\Selection\Collection;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Pricing\Price\BasePrice;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Pricing\Amount\Base;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Tax\Helper\Data;
use Magento\Tax\Observer\GetPriceConfigurationObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GetPriceConfigurationObserverTest extends TestCase
{
    /**
     * @var GetPriceConfigurationObserver
     */
    protected $model;

    /**
     * @var Registry|MockObject
     */
    protected $registry;

    /**
     * @var Data|MockObject
     */
    protected $taxData;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * test Execute
     * @dataProvider getPriceConfigurationProvider
     * @param array $testArray
     * @param array $expectedArray
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testExecute($testArray, $expectedArray)
    {
        $configObj = new DataObject(
            [
                'config' => $testArray,
            ]
        );

        $this->objectManager = new ObjectManager($this);

        $className = Registry::class;
        $this->registry = $this->createMock($className);

        $className = Data::class;
        $this->taxData = $this->createMock($className);

        $observerObject = $this->createMock(Observer::class);
        $observerObject->expects($this->any())
            ->method('getData')
            ->with('configObj')
            ->willReturn($configObj);

        $baseAmount = $this->createPartialMock(
            Base::class,
            ['getBaseAmount', 'getAdjustmentAmount', 'hasAdjustment']
        );

        $baseAmount->expects($this->any())
            ->method('hasAdjustment')
            ->willReturn(true);

        $baseAmount->expects($this->any())
            ->method('getBaseAmount')
            ->willReturn(33.5);

        $baseAmount->expects($this->any())
            ->method('getAdjustmentAmount')
            ->willReturn(1.5);

        $priceInfo = $this->createPartialMock(\Magento\Framework\Pricing\PriceInfo\Base::class, ['getPrice']);

        $basePrice = $this->createPartialMock(BasePrice::class, ['getAmount']);

        $basePrice->expects($this->any())
            ->method('getAmount')
            ->willReturn($baseAmount);

        $priceInfo->expects($this->any())
            ->method('getPrice')
            ->willReturn($basePrice);

        $prod1 = $this->createPartialMock(Product::class, ['getId', 'getPriceInfo']);
        $prod2 = $this->createMock(Product::class);

        $prod1->expects($this->any())
            ->method('getId')
            ->willReturn(1);

        $prod1->expects($this->any())
            ->method('getPriceInfo')
            ->willReturn($priceInfo);

        $optionCollection =
            $this->createPartialMock(Collection::class, ['getItems']);

        $optionCollection->expects($this->any())
            ->method('getItems')
            ->willReturn([$prod1, $prod2]);

        $productInstance =
            $this->getMockBuilder(Type::class)
                ->addMethods(['setStoreFilter', 'getSelectionsCollection', 'getOptionsIds'])
                ->disableOriginalConstructor()
                ->getMock();

        $product = $this->getMockBuilder(\Magento\Bundle\Model\Product\Type::class)
            ->addMethods(['getTypeInstance', 'getTypeId', 'getStoreId', 'getId'])
            ->onlyMethods(['getSelectionsCollection'])
            ->disableOriginalConstructor()
            ->getMock();
        $product->expects($this->any())
            ->method('getTypeInstance')
            ->willReturn($productInstance);
        $product->expects($this->any())
            ->method('getTypeId')
            ->willReturn('bundle');
        $product->expects($this->any())
            ->method('getStoreId')
            ->willReturn(null);

        $productInstance->expects($this->any())
            ->method('getSelectionsCollection')
            ->willReturn($optionCollection);

        $productInstance->expects($this->any())
            ->method('getOptionsIds')
            ->willReturn(true);

        $this->registry->expects($this->any())
            ->method('registry')
            ->with('current_product')
            ->willReturn($product);

        $this->taxData->expects($this->any())
            ->method('displayPriceIncludingTax')
            ->willReturn(true);

        $objectManager = new ObjectManager($this);
        $this->model = $objectManager->getObject(
            GetPriceConfigurationObserver::class,
            [
                'taxData' => $this->taxData,
                'registry' => $this->registry,
            ]
        );

        $this->model->execute($observerObject);

        $this->assertEquals($expectedArray, $configObj->getData('config'));
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getPriceConfigurationProvider()
    {
        return [
            "basic" => [
                'testArray' => [
                    [
                        [
                            'optionId' => 1,
                            'prices' => [
                                'finalPrice' => ['amount' => 35.50],
                                'basePrice' => ['amount' => 30.50],
                            ],
                        ],
                        [
                            'optionId' => 2,
                            'prices' => [
                                'finalPrice' => ['amount' => 333.50],
                                'basePrice' => ['amount' => 300.50],
                            ],
                        ],
                    ],
                ],
                'expectedArray' => [
                    [
                        [
                            'optionId' => 1,
                            'prices' => [
                                'finalPrice' => ['amount' => 35.50],
                                'basePrice' => ['amount' => 35],
                                'oldPrice' => ['amount' => 35],
                            ],
                        ],
                        [
                            'optionId' => 2,
                            'prices' => [
                                'finalPrice' => ['amount' => 333.50],
                                'basePrice' => ['amount' => 300.50],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
