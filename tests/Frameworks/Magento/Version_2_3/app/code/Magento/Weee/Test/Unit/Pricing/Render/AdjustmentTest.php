<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Weee\Test\Unit\Pricing\Render;

use Magento\Weee\Pricing\Render\Adjustment;

/**
 * Class AdjustmentTest for testing Adjustment class
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AdjustmentTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Weee\Pricing\Render\Adjustment
     */
    protected $model;

    /**
     * @var \Magento\Weee\Helper\Data
     */
    protected $weeeHelperMock;

    /**
     * Context mock
     *
     * @var \Magento\Framework\View\Element\Template\Context
     */
    protected $contextMock;

    /**
     * Price currency model mock
     *
     * @var \Magento\Directory\Model\PriceCurrency
     */
    protected $priceCurrencyMock;

    /**
     * Set up mocks for tested class
     */
    protected function setUp(): void
    {
        $this->contextMock = $this->createPartialMock(
            \Magento\Framework\View\Element\Template\Context::class,
            ['getStoreConfig', 'getEventManager', 'getScopeConfig']
        );
        $this->priceCurrencyMock = $this->getMockForAbstractClass(
            \Magento\Framework\Pricing\PriceCurrencyInterface::class,
            [],
            '',
            true,
            true,
            true,
            []
        );
        $this->weeeHelperMock = $this->createMock(\Magento\Weee\Helper\Data::class);
        $eventManagerMock = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $storeConfigMock = $this->getMockBuilder(\Magento\Store\Model\Store\Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $scopeConfigMock = $this->getMockForAbstractClass(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $this->contextMock->expects($this->any())
            ->method('getEventManager')
            ->willReturn($eventManagerMock);
        $this->contextMock->expects($this->any())
            ->method('getStoreConfig')
            ->willReturn($storeConfigMock);
        $this->contextMock->expects($this->any())
            ->method('getScopeConfig')
            ->willReturn($scopeConfigMock);

        $this->model = new Adjustment(
            $this->contextMock,
            $this->priceCurrencyMock,
            $this->weeeHelperMock
        );
    }

    /**
     * Test for method getAdjustmentCode
     */
    public function testGetAdjustmentCode()
    {
        $this->assertEquals(\Magento\Weee\Pricing\Adjustment::ADJUSTMENT_CODE, $this->model->getAdjustmentCode());
    }

    /**
     * Test for method getFinalAmount
     */
    public function testGetFinalAmount()
    {
        $this->priceCurrencyMock->expects($this->once())
            ->method('format')
            ->with(10, true, 2)
            ->willReturn("$10.00");

        $displayValue = 10;
        $expectedValue = "$10.00";
        $typeOfDisplay = 1; //Just to set it to not false
        /** @var \Magento\Framework\Pricing\Render\Amount $amountRender */
        $amountRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\Amount::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSaleableItem', 'getDisplayValue', 'getAmount'])
            ->getMock();
        $amountRender->expects($this->any())
            ->method('getDisplayValue')
            ->willReturn($displayValue);
        $this->weeeHelperMock->expects($this->any())->method('typeOfDisplay')->willReturn($typeOfDisplay);
        /** @var \Magento\Framework\Pricing\Amount\Base $baseAmount */
        $baseAmount = $this->getMockBuilder(\Magento\Framework\Pricing\Amount\Base::class)
            ->disableOriginalConstructor()
            ->setMethods(['getValue'])
            ->getMock();
        $amountRender->expects($this->any())
            ->method('getAmount')
            ->willReturn($baseAmount);

        $this->model->render($amountRender);
        $result = $this->model->getFinalAmount();

        $this->assertEquals($expectedValue, $result);
    }

    /**
     * Test for method showInclDescr
     *
     * @dataProvider showInclDescrDataProvider
     */
    public function testShowInclDescr($typeOfDisplay, $amount, $expectedResult)
    {
        /** @var \Magento\Framework\Pricing\Render\Amount $amountRender */
        $amountRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\Amount::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSaleableItem', 'getDisplayValue', 'getAmount'])
            ->getMock();
        /** @var \Magento\Catalog\Model\Product $saleable */
        $saleable = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['__wakeup'])
            ->getMock();
        /** @var \Magento\Framework\Pricing\Amount\Base $baseAmount */
        $baseAmount = $this->getMockBuilder(\Magento\Framework\Pricing\Amount\Base::class)
            ->disableOriginalConstructor()
            ->setMethods(['getValue'])
            ->getMock();

        $baseAmount->expects($this->any())
            ->method('getValue')
            ->willReturn($amount);

        $amountRender->expects($this->any())
            ->method('getAmount')
            ->willReturn($baseAmount);

        $callback = function ($argument) use ($typeOfDisplay) {
            if (is_array($argument)) {
                return in_array($typeOfDisplay, $argument);
            } else {
                return $argument == $typeOfDisplay;
            }
        };

        $this->weeeHelperMock->expects($this->any())->method('typeOfDisplay')->willReturnCallback($callback);
        $this->weeeHelperMock->expects($this->any())->method('getAmountExclTax')->willReturn($amount);
        $amountRender->expects($this->any())->method('getSaleableItem')->willReturn($saleable);

        $this->model->render($amountRender);
        $result = $this->model->showInclDescr();

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testShowInclDescr
     *
     * @return array
     */
    public function showInclDescrDataProvider()
    {
        return [
            [\Magento\Weee\Model\Tax::DISPLAY_INCL, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR, 1.23, true],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL, 1.23, false],
            [4, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL, 0, false],
            [4, 0, false],
        ];
    }

    /**
     * Test method for showExclDescrIncl
     *
     * @param int $typeOfDisplay
     * @param float $amount
     * @param bool $expectedResult
     * @dataProvider showExclDescrInclDataProvider
     */
    public function testShowExclDescrIncl($typeOfDisplay, $amount, $expectedResult)
    {
        /** @var \Magento\Framework\Pricing\Render\Amount $amountRender */
        $amountRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\Amount::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSaleableItem', 'getDisplayValue', 'getAmount'])
            ->getMock();
        /** @var \Magento\Catalog\Model\Product $saleable */
        $saleable = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['__wakeup'])
            ->getMock();
        /** @var \Magento\Framework\Pricing\Amount\Base $baseAmount */
        $baseAmount = $this->getMockBuilder(\Magento\Framework\Pricing\Amount\Base::class)
            ->disableOriginalConstructor()
            ->setMethods(['getValue'])
            ->getMock();
        $baseAmount->expects($this->any())
            ->method('getValue')
            ->willReturn($amount);
        $amountRender->expects($this->any())
            ->method('getAmount')
            ->willReturn($baseAmount);

        $callback = function ($argument) use ($typeOfDisplay) {
            if (is_array($argument)) {
                return in_array($typeOfDisplay, $argument);
            } else {
                return $argument == $typeOfDisplay;
            }
        };

        $this->weeeHelperMock->expects($this->any())->method('typeOfDisplay')->willReturnCallback($callback);
        $this->weeeHelperMock->expects($this->any())->method('getAmountExclTax')->willReturn($amount);
        $amountRender->expects($this->any())->method('getSaleableItem')->willReturn($saleable);

        $this->model->render($amountRender);
        $result = $this->model->showExclDescrIncl();

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testShowExclDescrIncl
     *
     * @return array
     */
    public function showExclDescrInclDataProvider()
    {
        return [
            [\Magento\Weee\Model\Tax::DISPLAY_INCL, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL, 1.23, true],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL, 1.23, false],
            [4, 1.23, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL, 0, false],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL, 0, false],
            [4, 0, false],
        ];
    }

    /**
     * Test for method getWeeeTaxAttributes
     *
     * @param int $typeOfDisplay
     * @param array $attributes
     * @param array $expectedResult
     * @dataProvider getWeeeTaxAttributesDataProvider
     */
    public function testGetWeeeTaxAttributes($typeOfDisplay, $attributes, $expectedResult)
    {
        /** @var \Magento\Framework\Pricing\Render\Amount $amountRender */
        $amountRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\Amount::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSaleableItem', 'getDisplayValue', 'getAmount'])
            ->getMock();
        /** @var \Magento\Catalog\Model\Product $saleable */
        $saleable = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['__wakeup'])
            ->getMock();
        /** @var \Magento\Framework\Pricing\Amount\Base $baseAmount */
        $baseAmount = $this->getMockBuilder(\Magento\Framework\Pricing\Amount\Base::class)
            ->disableOriginalConstructor()
            ->setMethods(['getValue'])
            ->getMock();
        $amountRender->expects($this->any())
            ->method('getAmount')
            ->willReturn($baseAmount);
        $callback = function ($argument) use ($typeOfDisplay) {
            if (is_array($argument)) {
                return in_array($typeOfDisplay, $argument);
            } else {
                return $argument == $typeOfDisplay;
            }
        };
        $this->weeeHelperMock->expects($this->any())->method('typeOfDisplay')->willReturnCallback($callback);
        $this->weeeHelperMock->expects($this->any())
            ->method('getProductWeeeAttributesForDisplay')
            ->willReturn($attributes);
        $amountRender->expects($this->any())->method('getSaleableItem')->willReturn($saleable);

        $this->model->render($amountRender);
        $result = $this->model->getWeeeTaxAttributes();

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testGetWeeeTaxAttributes
     *
     * @return array
     */
    public function getWeeeTaxAttributesDataProvider()
    {
        return [
            [\Magento\Weee\Model\Tax::DISPLAY_INCL, [1, 2, 3], []],
            [\Magento\Weee\Model\Tax::DISPLAY_INCL_DESCR, [1, 2, 3], [1, 2, 3]],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL_DESCR_INCL, [1, 2, 3], [1, 2, 3]],
            [\Magento\Weee\Model\Tax::DISPLAY_EXCL, [1, 2, 3], []],
            [4, [1, 2, 3], []],
        ];
    }

    /**
     * Test for method renderWeeeTaxAttribute
     *
     * @param \Magento\Framework\DataObject $attribute
     * @param string $expectedResult
     * @dataProvider renderWeeeTaxAttributeAmountDataProvider
     */
    public function testRenderWeeeTaxAttributeAmount($attribute, $expectedResult)
    {
        $this->priceCurrencyMock->expects($this->any())->method('convertAndFormat')->willReturnArgument(0);

        $result = $this->model->renderWeeeTaxAttribute($attribute);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testRenderWeeeTaxAttributeAmount
     *
     * @return array
     */
    public function renderWeeeTaxAttributeAmountDataProvider()
    {
        return [
            [new \Magento\Framework\DataObject(['amount' => 51]), 51],
            [new \Magento\Framework\DataObject(['amount' => false]), false],
        ];
    }

    /**
     * Test for method renderWeeeTaxAttributeName
     *
     * @param \Magento\Framework\DataObject $attribute
     * @param string $expectedResult
     * @dataProvider renderWeeeTaxAttributeNameDataProvider
     */
    public function testRenderWeeeTaxAttributeName($attribute, $expectedResult)
    {
        $this->priceCurrencyMock->expects($this->any())->method('convertAndFormat')->willReturnArgument(0);

        $result = $this->model->renderWeeeTaxAttributeName($attribute);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testRenderWeeeTaxAttributeName
     *
     * @return array
     */
    public function renderWeeeTaxAttributeNameDataProvider()
    {
        return [
            [new \Magento\Framework\DataObject(['name' => 51]), 51],
            [new \Magento\Framework\DataObject(['name' => false]), false],
        ];
    }

    /**
     * Test for method renderWeeeTaxAttributeWithTax
     *
     * @param \Magento\Framework\DataObject $attribute
     * @param string $expectedResult
     * @dataProvider renderWeeeTaxAttributeAmountWithTaxDataProvider
     */
    public function testRenderWeeeTaxAttributeWithTax($attribute, $expectedResult)
    {
        $this->priceCurrencyMock->expects($this->any())->method('convertAndFormat')->willReturnArgument(0);

        $result = $this->model->renderWeeeTaxAttributeWithTax($attribute);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Data provider for testRenderWeeeTaxAttributeAmount
     *
     * @return array
     */
    public function renderWeeeTaxAttributeAmountWithTaxDataProvider()
    {
        return [
            [new \Magento\Framework\DataObject(['amount_excl_tax' => 50, 'tax_amount' => 5]), 55],
            [new \Magento\Framework\DataObject(['amount_excl_tax' => false]), false],
        ];
    }
}
