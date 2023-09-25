<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Paypal\Test\Unit\Helper\Shortcut;

class ValidatorTest extends \PHPUnit\Framework\TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $_paypalConfigFactory;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $_registry;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $_productTypeConfig;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $_paymentData;

    /** @var \Magento\Paypal\Helper\Shortcut\Validator */
    protected $helper;

    protected function setUp(): void
    {
        $this->_paypalConfigFactory = $this->createPartialMock(\Magento\Paypal\Model\ConfigFactory::class, ['create']);
        $this->_productTypeConfig = $this->createMock(\Magento\Catalog\Model\ProductTypes\ConfigInterface::class);
        $this->_registry = $this->createMock(\Magento\Framework\Registry::class);
        $this->_paymentData = $this->createMock(\Magento\Payment\Helper\Data::class);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->helper = $objectManager->getObject(
            \Magento\Paypal\Helper\Shortcut\Validator::class,
            [
                'paypalConfigFactory' => $this->_paypalConfigFactory,
                'registry' => $this->_registry,
                'productTypeConfig' => $this->_productTypeConfig,
                'paymentData' => $this->_paymentData
            ]
        );
    }

    /**
     * @dataProvider isContextAvailableDataProvider
     * @param bool $isVisible
     * @param bool $expected
     */
    public function testIsContextAvailable($isVisible, $expected)
    {
        $paypalConfig = $this->getMockBuilder(\Magento\Paypal\Model\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paypalConfig->expects($this->any())
            ->method('getValue')
            ->with($this->stringContains('visible_on'))
            ->willReturn($isVisible);

        $this->_paypalConfigFactory->expects($this->any())
            ->method('create')
            ->willReturn($paypalConfig);

        $this->assertEquals($expected, $this->helper->isContextAvailable('payment_code', true));
    }

    /**
     * @return array
     */
    public function isContextAvailableDataProvider()
    {
        return [
            [false, false],
            [true, true]
        ];
    }

    /**
     * @dataProvider isPriceOrSetAvailableDataProvider
     * @param bool $isInCatalog
     * @param double $productPrice
     * @param bool $isProductSet
     * @param bool $expected
     */
    public function testIsPriceOrSetAvailable($isInCatalog, $productPrice, $isProductSet, $expected)
    {
        $currentProduct = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)->disableOriginalConstructor()
            ->setMethods(['__wakeup', 'getFinalPrice', 'getTypeId', 'getTypeInstance'])
            ->getMock();
        $typeInstance = $this->getMockBuilder(\Magento\Catalog\Model\Product\Type\AbstractType::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();
        $currentProduct->expects($this->any())->method('getFinalPrice')->willReturn($productPrice);
        $currentProduct->expects($this->any())->method('getTypeId')->willReturn('simple');
        $currentProduct->expects($this->any())->method('getTypeInstance')->willReturn($typeInstance);

        $this->_registry->expects($this->any())
            ->method('registry')
            ->with($this->equalTo('current_product'))
            ->willReturn($currentProduct);

        $this->_productTypeConfig->expects($this->any())
            ->method('isProductSet')
            ->willReturn($isProductSet);

        $typeInstance->expects($this->any())
            ->method('canConfigure')
            ->with($currentProduct)
            ->willReturn(false);

        $this->assertEquals($expected, $this->helper->isPriceOrSetAvailable($isInCatalog));
    }

    /**
     * @return array
     */
    public function isPriceOrSetAvailableDataProvider()
    {
        return [
            [false, 1, true, true],
            [false, null, null, true],
            [true, 0, false, false],
            [true, 10, false, true],
            [true, 0, true, true]
        ];
    }

    /**
     * @dataProvider isMethodAvailableDataProvider
     * @param bool $methodIsAvailable
     * @param bool $expected
     */
    public function testIsMethodAvailable($methodIsAvailable, $expected)
    {
        $methodInstance = $this->getMockBuilder(\Magento\Payment\Model\MethodInterface::class)
            ->getMockForAbstractClass();
        $methodInstance->expects($this->any())
            ->method('isAvailable')
            ->willReturn($methodIsAvailable);

        $this->_paymentData->expects($this->any())
            ->method('getMethodInstance')
            ->willReturn(
                $methodInstance
            );

        $this->assertEquals($expected, $this->helper->isMethodAvailable('payment_code'));
    }

    /**
     * @return array
     */
    public function isMethodAvailableDataProvider()
    {
        return [
            [true, true],
            [false, false]
        ];
    }
}
