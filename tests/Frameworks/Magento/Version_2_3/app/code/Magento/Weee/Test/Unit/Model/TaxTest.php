<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Weee\Test\Unit\Model;

/**
 * Class TaxTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Weee\Model\Tax
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $context;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $registry;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $attributeFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $calculationFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSession;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $accountManagement;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $taxData;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resource;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $weeeConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrency;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceCollection;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $data;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * Setup the test
     */
    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $className = \Magento\Framework\Model\Context::class;
        $this->context = $this->createMock($className);

        $className = \Magento\Framework\Registry::class;
        $this->registry = $this->createMock($className);

        $className = \Magento\Eav\Model\Entity\AttributeFactory::class;
        $this->attributeFactory = $this->createPartialMock($className, ['create']);

        $className = \Magento\Store\Model\StoreManagerInterface::class;
        $this->storeManager = $this->createMock($className);

        $className = \Magento\Tax\Model\CalculationFactory::class;
        $this->calculationFactory = $this->createPartialMock($className, ['create']);

        $className = \Magento\Customer\Model\Session::class;
        $this->customerSession = $this->createPartialMock(
            $className,
            ['getCustomerId', 'getDefaultTaxShippingAddress', 'getDefaultTaxBillingAddress', 'getCustomerTaxClassId']
        );
        $this->customerSession->expects($this->any())->method('getCustomerId')->willReturn(null);
        $this->customerSession->expects($this->any())->method('getDefaultTaxShippingAddress')->willReturn(null);
        $this->customerSession->expects($this->any())->method('getDefaultTaxBillingAddress')->willReturn(null);
        $this->customerSession->expects($this->any())->method('getCustomerTaxClassId')->willReturn(null);

        $className = \Magento\Customer\Api\AccountManagementInterface::class;
        $this->accountManagement = $this->createMock($className);

        $className = \Magento\Tax\Helper\Data::class;
        $this->taxData = $this->createMock($className);

        $className = \Magento\Weee\Model\ResourceModel\Tax::class;
        $this->resource = $this->createMock($className);

        $className = \Magento\Weee\Model\Config::class;
        $this->weeeConfig = $this->createMock($className);

        $className = \Magento\Framework\Pricing\PriceCurrencyInterface::class;
        $this->priceCurrency = $this->createMock($className);

        $className = \Magento\Framework\Data\Collection\AbstractDb::class;
        $this->resourceCollection = $this->createMock($className);

        $this->model = $this->objectManager->getObject(
            \Magento\Weee\Model\Tax::class,
            [
                'context' => $this->context,
                'registry' => $this->registry,
                'attributeFactory' => $this->attributeFactory,
                'storeManager' => $this->storeManager,
                'calculationFactory' => $this->calculationFactory,
                'customerSession' => $this->customerSession,
                'accountManagement' => $this->accountManagement,
                'taxData' => $this->taxData,
                'resource' => $this->resource,
                'weeeConfig' => $this->weeeConfig,
                'priceCurrency' => $this->priceCurrency,
                'resourceCollection' => $this->resourceCollection,
            ]
        );
    }

    /**
     * @dataProvider getProductWeeeAttributesDataProvider
     * @param array $weeeTaxCalculationsByEntity
     * @param mixed $websitePassed
     * @param string $expectedFptLabel
     * @return void
     */
    public function testGetProductWeeeAttributes(
        array $weeeTaxCalculationsByEntity,
        $websitePassed,
        string $expectedFptLabel
    ): void {
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $website = $this->createMock(\Magento\Store\Model\Website::class);
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $group = $this->createMock(\Magento\Store\Model\Group::class);

        $attribute = $this->createMock(\Magento\Eav\Model\Entity\Attribute::class);
        $calculation = $this->createMock(\Magento\Tax\Model\Calculation::class);

        $obj = new \Magento\Framework\DataObject(['country' => 'US', 'region' => 'TX']);
        $calculation->expects($this->once())
            ->method('getRateRequest')
            ->willReturn($obj);
        $calculation->expects($this->once())
            ->method('getDefaultRateRequest')
            ->willReturn($obj);
        $calculation->expects($this->any())
            ->method('getRate')
            ->willReturn('10');

        $attribute->expects($this->once())
            ->method('getAttributeCodesByFrontendType')
            ->willReturn(['0'=>'fpt']);

        $this->storeManager->expects($this->any())
            ->method('getWebsite')
            ->willReturn($website);
        $website->expects($this->any())
            ->method('getId')
            ->willReturn($websitePassed);
        $website->expects($this->any())
            ->method('getDefaultGroup')
            ->willReturn($group);
        $group->expects($this->any())
            ->method('getDefaultStore')
            ->willReturn($store);
        $store->expects($this->any())
            ->method('getId')
            ->willReturn(1);

        if ($websitePassed) {
            $product->expects($this->never())
                ->method('getStore')
                ->willReturn($store);
        } else {
            $product->expects($this->once())
                ->method('getStore')
                ->willReturn($store);
            $store->expects($this->once())
                ->method('getWebsiteId')
                ->willReturn(1);
        }

        $product->expects($this->any())
            ->method('getId')
            ->willReturn(1);

        $this->weeeConfig->expects($this->any())
            ->method('isEnabled')
            ->willReturn(true);

        $this->weeeConfig->expects($this->any())
            ->method('isTaxable')
            ->willReturn(true);

        $this->attributeFactory->expects($this->any())
            ->method('create')
            ->willReturn($attribute);

        $this->calculationFactory->expects($this->any())
            ->method('create')
            ->willReturn($calculation);

        $this->priceCurrency->expects($this->any())
            ->method('round')
            ->with(0.1)
            ->willReturn(0.25);

        $this->resource->expects($this->any())
            ->method('fetchWeeeTaxCalculationsByEntity')
            ->willReturn([
                0 => $weeeTaxCalculationsByEntity
            ]);

        $result = $this->model->getProductWeeeAttributes($product, null, null, $websitePassed, true);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $obj = $result[0];
        $this->assertEquals(1, $obj->getAmount());
        $this->assertEquals(0.25, $obj->getTaxAmount());
        $this->assertEquals($weeeTaxCalculationsByEntity['attribute_code'], $obj->getCode());
        $this->assertEquals(__($expectedFptLabel), $obj->getName());
    }

    /**
     * Test getWeeeAmountExclTax method
     *
     * @param string $productTypeId
     * @param string $productPriceType
     * @dataProvider getWeeeAmountExclTaxDataProvider
     */
    public function testGetWeeeAmountExclTax($productTypeId, $productPriceType)
    {
        $product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTypeId', 'getPriceType'])
            ->getMock();
        $product->expects($this->any())->method('getTypeId')->willReturn($productTypeId);
        $product->expects($this->any())->method('getPriceType')->willReturn($productPriceType);
        $weeeDataHelper = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAmountExclTax'])
            ->getMock();
        $weeeDataHelper->expects($this->at(0))->method('getAmountExclTax')->willReturn(10);
        $weeeDataHelper->expects($this->at(1))->method('getAmountExclTax')->willReturn(30);
        $tax = $this->getMockBuilder(\Magento\Weee\Model\Tax::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProductWeeeAttributes'])
            ->getMock();
        $tax->expects($this->once())->method('getProductWeeeAttributes')
            ->willReturn([$weeeDataHelper, $weeeDataHelper]);
        $this->assertEquals(40, $tax->getWeeeAmountExclTax($product));
    }

    /**
     * Test getWeeeAmountExclTax method for dynamic bundle product
     */
    public function testGetWeeeAmountExclTaxForDynamicBundleProduct()
    {
        $product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTypeId', 'getPriceType'])
            ->getMock();
        $product->expects($this->once())->method('getTypeId')->willReturn('bundle');
        $product->expects($this->once())->method('getPriceType')->willReturn(0);
        $weeeDataHelper = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();
        $tax = $this->getMockBuilder(\Magento\Weee\Model\Tax::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProductWeeeAttributes'])
            ->getMock();
        $tax->expects($this->once())->method('getProductWeeeAttributes')->willReturn([$weeeDataHelper]);
        $this->assertEquals(0, $tax->getWeeeAmountExclTax($product));
    }

    /**
     * @return array
     */
    public function getProductWeeeAttributesDataProvider()
    {
        return [
            'store_label_defined' => [
                'weeeTaxCalculationsByEntity' => [
                    'weee_value' => 1,
                    'label_value' => 'fpt_label',
                    'frontend_label' => 'fpt_label_frontend',
                    'attribute_code' => 'fpt_code',
                ],
                'websitePassed' => 1,
                'expectedFptLabel' => 'fpt_label',
            ],
            'store_label_not_defined' => [
                'weeeTaxCalculationsByEntity' => [
                    'weee_value' => 1,
                    'label_value' => '',
                    'frontend_label' => 'fpt_label_frontend',
                    'attribute_code' => 'fpt_code',
                ],
                'websitePassed' => 1,
                'expectedFptLabel' => 'fpt_label_frontend',
            ],
            'website_not_passed' => [
                'weeeTaxCalculationsByEntity' => [
                    'weee_value' => 1,
                    'label_value' => '',
                    'frontend_label' => 'fpt_label_frontend',
                    'attribute_code' => 'fpt_code',
                ],
                'websitePassed' => null,
                'expectedFptLabel' => 'fpt_label_frontend',
            ],
        ];
    }

    /**
     * @return array
     */
    public function getWeeeAmountExclTaxDataProvider()
    {
        return [
            [
                'bundle', 1
            ],
            [
                'simple', 0
            ],
            [
                'simple', 1
            ]
        ];
    }
}
