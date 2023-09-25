<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Test\Unit\Model\Quote;

use Magento\Directory\Model\Currency;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\Collection as RatesCollection;
use Magento\Shipping\Model\Rate\Result;
use Magento\Store\Model\ScopeInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Quote\Model\Quote\Address\RateCollectorInterfaceFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateRequestFactory;
use Magento\Quote\Model\Quote\Address\RateCollectorInterface;
use Magento\Quote\Model\ResourceModel\Quote\Address\Item\CollectionFactory;
use Magento\Quote\Model\ResourceModel\Quote\Address\Item\Collection;
use Magento\Directory\Model\Region;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Quote\Model\Quote\Address\RateResult\AbstractResult;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Test class for sales quote address model
 *
 * @see \Magento\Quote\Model\Quote\Address
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AddressTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Address
     */
    private $address;

    /**
     * @var \Magento\Quote\Model\Quote | \PHPUnit\Framework\MockObject\MockObject
     */
    private $quote;

    /**
     * @var \Magento\Quote\Model\Quote\Address\CustomAttributeListInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $attributeList;

    /**
     * @var \Magento\Framework\App\Config | \PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    /**
     * @var RateRequestFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $requestFactory;

    /**
     * @var RateFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $addressRateFactory;

    /**
     * @var RateCollectionFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $rateCollectionFactory;

    /**
     * @var RateCollectorInterfaceFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $rateCollector;

    /**
     * @var RateCollectorInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $rateCollection;

    /**
     * @var CollectionFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $itemCollectionFactory;

    /**
     * @var RegionFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    private $regionFactory;

    /**
     * @var StoreManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManager;

    /**
     * @var StoreInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $store;

    /**
     * @var WebsiteInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $website;

    /**
     * @var Region | \PHPUnit\Framework\MockObject\MockObject
     */
    private $region;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $serializer;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config::class);
        $this->serializer = new Json();

        $this->requestFactory = $this->getMockBuilder(RateRequestFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->addressRateFactory = $this->getMockBuilder(RateFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->rateCollector = $this->getMockBuilder(RateCollectorInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->rateCollectionFactory = $this->getMockBuilder(RateCollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->rateCollection = $this->getMockBuilder(RateCollectorInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getResult'])
            ->getMockForAbstractClass();

        $this->itemCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->regionFactory = $this->getMockBuilder(RegionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->region = $this->getMockBuilder(Region::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeManager = $this->getMockBuilder(StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->store = $this->getMockBuilder(StoreInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getBaseCurrency', 'getCurrentCurrency', 'getCurrentCurrencyCode'])
            ->getMockForAbstractClass();

        $this->website = $this->getMockBuilder(WebsiteInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->attributeList = $this->createMock(
            \Magento\Quote\Model\Quote\Address\CustomAttributeListInterface::class
        );
        $this->attributeList->method('getAttributes')->willReturn([]);

        $this->address = $objectManager->getObject(
            \Magento\Quote\Model\Quote\Address::class,
            [
                'attributeList' => $this->attributeList,
                'scopeConfig' => $this->scopeConfig,
                'serializer' => $this->serializer,
                'storeManager' => $this->storeManager,
                '_itemCollectionFactory' => $this->itemCollectionFactory,
                '_rateRequestFactory' => $this->requestFactory,
                '_rateCollectionFactory' => $this->rateCollectionFactory,
                '_rateCollector' => $this->rateCollector,
                '_regionFactory' => $this->regionFactory,
                '_addressRateFactory' => $this->addressRateFactory
            ]
        );
        $this->quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $this->address->setQuote($this->quote);
    }

    public function testValidateMinimumAmountDisabled()
    {
        $storeId = 1;

        $this->quote->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId)
            ->willReturn(false);

        $this->assertTrue($this->address->validateMinimumAmount());
    }

    public function testValidateMinimumAmountVirtual()
    {
        $storeId = 1;
        $scopeConfigValues = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];

        $this->quote->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);
        $this->quote->expects($this->once())
            ->method('getIsVirtual')
            ->willReturn(true);
        $this->address->setAddressType(Address::TYPE_SHIPPING);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->willReturnMap($scopeConfigValues);

        $this->assertTrue($this->address->validateMinimumAmount());
    }

    public function testValidateMinimumAmount()
    {
        $storeId = 1;
        $scopeConfigValues = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];

        $this->quote->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);
        $this->quote->expects($this->once())
            ->method('getIsVirtual')
            ->willReturn(false);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->willReturnMap($scopeConfigValues);

        $this->assertTrue($this->address->validateMinimumAmount());
    }

    public function testValidateMiniumumAmountWithoutDiscount()
    {
        $storeId = 1;
        $scopeConfigValues = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, false],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];

        $this->quote->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);
        $this->quote->expects($this->once())
            ->method('getIsVirtual')
            ->willReturn(false);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->willReturnMap($scopeConfigValues);

        $this->assertTrue($this->address->validateMinimumAmount());
    }

    public function testValidateMinimumAmountNegative()
    {
        $storeId = 1;
        $scopeConfigValues = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];

        $this->quote->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);
        $this->quote->expects($this->once())
            ->method('getIsVirtual')
            ->willReturn(false);
        $this->address->setAddressType(Address::TYPE_SHIPPING);

        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->willReturnMap($scopeConfigValues);

        $this->assertTrue($this->address->validateMinimumAmount());
    }

    public function testSetAndGetAppliedTaxes()
    {
        $data = ['data'];
        self::assertInstanceOf(Address::class, $this->address->setAppliedTaxes($data));
        self::assertEquals($data, $this->address->getAppliedTaxes());
    }

    /**
     * Checks a case, when applied taxes are not provided.
     */
    public function testGetAppliedTaxesWithEmptyValue()
    {
        $this->address->setData('applied_taxes', null);
        self::assertEquals([], $this->address->getAppliedTaxes());
    }

    /**
     * Test of requesting shipping rates by address
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testRequestShippingRates()
    {
        $storeId = 12345;
        $webSiteId = 6789;
        $baseCurrency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCurrentCurrencyCode','convert'])
            ->getMockForAbstractClass();

        $currentCurrency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCurrentCurrencyCode','convert'])
            ->getMockForAbstractClass();

        $currentCurrencyCode = 'UAH';

        $this->quote->expects($this->any())
            ->method('getStoreId')
            ->willReturn($storeId);

        $this->storeManager->expects($this->at(0))
            ->method('getStore')
            ->with($storeId)
            ->willReturn($this->store);
        $this->store->expects($this->any())
            ->method('getWebsiteId')
            ->willReturn($webSiteId);

        $this->scopeConfig->expects($this->exactly(1))
            ->method('getValue')
            ->with(
                'tax/calculation/price_includes_tax',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
            ->willReturn(1);

        /** @var RateRequest */
        $request = $this->getMockBuilder(RateRequest::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'setStoreId',
                    'setWebsiteId',
                    'setBaseCurrency',
                    'setPackageCurrency',
                    'getBaseSubtotalTotalInclTax',
                    'getBaseSubtotal'
                ]
            )
            ->getMock();

        /** @var Collection */
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $collection->expects($this->once())
            ->method('setAddressFilter')
            ->willReturnSelf();

        /** @var RatesCollection */
        $ratesCollection = $this->getMockBuilder(RatesCollection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $ratesCollection->expects($this->once())
            ->method('setAddressFilter')
            ->willReturnSelf();

        /** @var Result */
        $rates = $this->getMockBuilder(Result::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var  AbstractResult */
        $rateItem = $this->getMockBuilder(AbstractResult::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        /** @var Rate */
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->getMock();

        $rate->expects($this->once())
            ->method('importShippingRate')
            ->willReturnSelf();

        $rates->expects($this->once())
            ->method('getAllRates')
            ->willReturn([$rateItem]);

        $this->requestFactory->expects($this->once())
            ->method('create')
            ->willReturn($request);

        $this->rateCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($ratesCollection);

        $this->rateCollector->expects($this->once())
            ->method('create')
            ->willReturn($this->rateCollection);

        $this->rateCollection->expects($this->once())
            ->method('collectRates')
            ->willReturnSelf();

        $this->rateCollection->expects($this->once())
            ->method('getResult')
            ->willReturn($rates);

        $this->itemCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($collection);

        $this->regionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->region);

        $this->region->expects($this->once())
            ->method('loadByCode')
            ->willReturnSelf();

        $this->storeManager->method('getStore')
            ->willReturn($this->store);

        $this->store->method('getBaseCurrency')
            ->willReturn($baseCurrency);

        $this->store->expects($this->once())
            ->method('getCurrentCurrency')
            ->willReturn($currentCurrency);

        $this->store->expects($this->once())
            ->method('getCurrentCurrencyCode')
            ->willReturn($currentCurrencyCode);

        $this->addressRateFactory->expects($this->once())
            ->method('create')
            ->willReturn($rate);

        $request->expects($this->once())
            ->method('setStoreId')
            ->with($storeId);

        $request->expects($this->once())
            ->method('setWebsiteId')
            ->with($webSiteId);

        $request->expects($this->once())
            ->method('setBaseCurrency')
            ->with($baseCurrency);

        $request->expects($this->once())
            ->method('setPackageCurrency')
            ->with($currentCurrency);

        $baseCurrency->expects($this->once())
            ->method('convert')
            ->with(null, $currentCurrencyCode);

        $this->address->requestShippingRates();
    }
}
