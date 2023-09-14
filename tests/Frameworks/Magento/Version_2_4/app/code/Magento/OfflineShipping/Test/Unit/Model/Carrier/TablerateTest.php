<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\OfflineShipping\Test\Unit\Model\Carrier;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\OfflineShipping\Model\Carrier\Tablerate;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\TablerateFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Sales\Model\Order\Item;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TablerateTest extends TestCase
{
    /**
     * @var Tablerate
     */
    private $model;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var ErrorFactory|MockObject
     */
    private $errorFactoryMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var ResultFactory|MockObject
     */
    private $resultFactoryMock;

    /**
     * @var MethodFactory|MockObject
     */
    private $methodFactoryMock;

    /**
     * @var TablerateFactory|MockObject
     */
    private $tablerateFactoryMock;

    /**
     * @var ObjectManager
     */
    private $helper;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['create', 'isSetFlag', 'getValue'])
            ->getMockForAbstractClass();

        $this->errorFactoryMock = $this
            ->getMockBuilder(ErrorFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->resultFactoryMock = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->methodFactoryMock = $this
            ->getMockBuilder(MethodFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->tablerateFactoryMock = $this
            ->getMockBuilder(TablerateFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create', 'getRate'])
            ->getMock();

        $this->helper = new ObjectManager($this);
        $this->model = $this->helper->getObject(
            Tablerate::class,
            [
                'scopeConfig' => $this->scopeConfigMock,
                'rateErrorFactory' => $this->errorFactoryMock,
                'logger' => $this->loggerMock,
                'rateResultFactory' => $this->resultFactoryMock,
                'resultMethodFactory' => $this->methodFactoryMock,
                'tablerateFactory' => $this->tablerateFactoryMock
            ]
        );
    }

    /**
     * @param bool $freeshipping
     * @dataProvider collectRatesWithGlobalFreeShippingDataProvider
     * @return void
     */
    public function testCollectRatesWithGlobalFreeShipping($freeshipping)
    {
        $rate = [
            'price' => 15,
            'cost' => 2
        ];

        $request = $this->getMockBuilder(RateRequest::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAllItems', 'getPackageQty', 'getFreeShipping'])
            ->getMock();

        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getProduct',
                    'getParentItem',
                    'getHasChildren',
                    'isShipSeparately',
                    'getChildren',
                    'getQty',
                    'getFreeShipping',
                    'getBaseRowTotal'
                ]
            )
            ->getMock();

        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['isVirtual'])
            ->getMock();

        $tablerate = $this->getMockBuilder(Tablerate::class)
            ->disableOriginalConstructor()
            ->setMethods(['getRate'])
            ->getMock();

        $this->scopeConfigMock->expects($this->any())->method('isSetFlag')->willReturn(true);

        $tablerate->expects($this->any())->method('getRate')->willReturn($rate);
        $this->tablerateFactoryMock->expects($this->once())->method('create')->willReturn($tablerate);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->setMethods(['setCarrier', 'setCarrierTitle', 'setMethod', 'setMethodTitle', 'setPrice', 'setCost'])
            ->getMock();
        $this->methodFactoryMock->expects($this->once())->method('create')->willReturn($method);

        $result = $this->getMockBuilder(Result::class)
            ->disableOriginalConstructor()
            ->setMethods(['append'])
            ->getMock();
        $this->resultFactoryMock->expects($this->once())->method('create')->willReturn($result);

        $product->expects($this->any())->method('isVirtual')->willReturn(false);

        $item->expects($this->any())->method('getProduct')->willReturn($product);
        $item->expects($this->any())->method('getFreeShipping')->willReturn(1);
        $item->expects($this->any())->method('getQty')->willReturn(1);

        $request->expects($this->any())->method('getAllItems')->willReturn([$item]);
        $request->expects($this->any())->method('getPackageQty')->willReturn(1);

        $returnPrice = null;
        $method->expects($this->once())->method('setPrice')->with($this->captureArg($returnPrice));

        $returnCost = null;
        $method->expects($this->once())->method('setCost')->with($this->captureArg($returnCost));

        $returnMethod = null;
        $result->expects($this->once())->method('append')->with($this->captureArg($returnMethod));

        $request->expects($this->never())->method('getFreeShipping')->willReturn($freeshipping);

        $returnResult = $this->model->collectRates($request);

        $this->assertEquals($rate['price'], $returnPrice);
        $this->assertEquals($rate['cost'], $returnCost);
        $this->assertEquals($method, $returnMethod);
        $this->assertEquals($result, $returnResult);
    }

    /**
     * Captures the argument and saves it in the given variable
     *
     * @param $captureVar
     * @return Callback
     */
    private function captureArg(&$captureVar)
    {
        return $this->callback(function ($argToMock) use (&$captureVar) {
            $captureVar = $argToMock;

            return true;
        });
    }

    /**
     * @return array
     */
    public function collectRatesWithGlobalFreeShippingDataProvider()
    {
        return [
            ['freeshipping' => true],
            ['freeshipping' => false]
        ];
    }
}
