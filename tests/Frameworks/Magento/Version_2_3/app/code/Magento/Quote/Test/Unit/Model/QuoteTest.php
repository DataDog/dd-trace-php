<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Test\Unit\Model;

use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\ScopeInterface;

/**
 * Test class for \Magento\Quote\Model
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class QuoteTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\Quote\AddressFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressFactoryMock;

    /**
     * @var \Magento\Quote\Model\Quote\Address|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressMock;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Address\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressCollectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $groupRepositoryMock;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $quote;

    /**
     * @var \Magento\Catalog\Model\Product |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var \Magento\Framework\DataObject\Factory |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectFactoryMock;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteItemCollectionFactoryMock;

    /**
     * @var \Magento\Quote\Model\Quote\PaymentFactory
     */
    protected $paymentFactoryMock;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment\CollectionFactory
     */
    protected $quotePaymentCollectionFactoryMock;

    /**
     * @var \Magento\Framework\App\Config | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $addressRepositoryMock;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $criteriaBuilderMock;

    /**
     * @var \Magento\Framework\Api\FilterBuilder | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $filterBuilderMock;

    /**
     * @var \Magento\Framework\Api\ExtensibleDataObjectConverter | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $extensibleDataObjectConverterMock;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerRepositoryMock;

    /**
     * @var \Magento\Framework\DataObject\Copy | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectCopyServiceMock;

    /**
     * @var JoinProcessorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extensionAttributesJoinProcessorMock;

    /**
     * @var \Magento\Customer\Api\Data\CustomerInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $customerDataFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $itemProcessor;

    /**
     * @var \Magento\Sales\Model\OrderIncrementIdChecker|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderIncrementIdChecker;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->quoteAddressFactoryMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\AddressFactory::class,
            ['create']
        );
        $this->quoteAddressMock = $this->createPartialMock(\Magento\Quote\Model\Quote\Address::class, [
                'isDeleted', 'getCollection', 'getId', 'getCustomerAddressId',
                '__wakeup', 'getAddressType', 'getDeleteImmediately', 'validateMinimumAmount', 'setData'
            ]);
        $this->quoteAddressCollectionMock = $this->createMock(
            \Magento\Quote\Model\ResourceModel\Quote\Address\Collection::class
        );
        $this->extensibleDataObjectConverterMock = $this->createPartialMock(
            \Magento\Framework\Api\ExtensibleDataObjectConverter::class,
            ['toFlatArray']
        );
        $this->customerRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\CustomerRepositoryInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getById', 'save']
        );
        $this->objectCopyServiceMock = $this->createPartialMock(
            \Magento\Framework\DataObject\Copy::class,
            ['copyFieldsetToTarget']
        );
        $this->productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $this->objectFactoryMock = $this->createPartialMock(\Magento\Framework\DataObject\Factory::class, ['create']);
        $this->quoteAddressFactoryMock->expects(
            $this->any()
        )->method(
            'create'
        )->willReturn(
            $this->quoteAddressMock
        );
        $this->quoteAddressMock->expects(
            $this->any()
        )->method(
            'getCollection'
        )->willReturn(
            $this->quoteAddressCollectionMock
        );
        $this->eventManagerMock = $this->getMockBuilder(\Magento\Framework\Event\Manager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(\Magento\Store\Model\StoreManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resourceMock = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock = $this->getMockBuilder(\Magento\Framework\Model\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerFactoryMock = $this->getMockBuilder(\Magento\Customer\Model\CustomerFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->groupRepositoryMock = $this->getMockBuilder(\Magento\Customer\Api\GroupRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->any())
            ->method('getEventDispatcher')
            ->willReturn($this->eventManagerMock);
        $this->quoteItemCollectionFactoryMock = $this->createPartialMock(
            \Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory::class,
            ['create']
        );
        $this->quotePaymentCollectionFactoryMock = $this->createPartialMock(
            \Magento\Quote\Model\ResourceModel\Quote\Payment\CollectionFactory::class,
            ['create']
        );
        $this->paymentFactoryMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\PaymentFactory::class,
            ['create']
        );
        $this->scopeConfig = $this->getMockBuilder(\Magento\Framework\App\Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->addressRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\AddressRepositoryInterface::class,
            [],
            '',
            false
        );

        $this->criteriaBuilderMock = $this->getMockBuilder(\Magento\Framework\Api\SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filterBuilderMock = $this->getMockBuilder(\Magento\Framework\Api\FilterBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->itemProcessor = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->extensionAttributesJoinProcessorMock = $this->createMock(
            \Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface::class
        );
        $this->customerDataFactoryMock = $this->createPartialMock(
            \Magento\Customer\Api\Data\CustomerInterfaceFactory::class,
            ['create']
        );
        $this->orderIncrementIdChecker = $this->createMock(\Magento\Sales\Model\OrderIncrementIdChecker::class);
        $this->quote = (new ObjectManager($this))
            ->getObject(
                \Magento\Quote\Model\Quote::class,
                [
                    'quoteAddressFactory' => $this->quoteAddressFactoryMock,
                    'storeManager' => $this->storeManagerMock,
                    'resource' => $this->resourceMock,
                    'context' => $this->contextMock,
                    'customerFactory' => $this->customerFactoryMock,
                    'groupRepository' => $this->groupRepositoryMock,
                    'objectFactory' => $this->objectFactoryMock,
                    'addressRepository' => $this->addressRepositoryMock,
                    'criteriaBuilder' => $this->criteriaBuilderMock,
                    'filterBuilder' => $this->filterBuilderMock,
                    'quoteItemCollectionFactory' => $this->quoteItemCollectionFactoryMock,
                    'quotePaymentCollectionFactory' => $this->quotePaymentCollectionFactoryMock,
                    'quotePaymentFactory' => $this->paymentFactoryMock,
                    'scopeConfig' => $this->scopeConfig,
                    'extensibleDataObjectConverter' => $this->extensibleDataObjectConverterMock,
                    'customerRepository' => $this->customerRepositoryMock,
                    'objectCopyService' => $this->objectCopyServiceMock,
                    'extensionAttributesJoinProcessor' => $this->extensionAttributesJoinProcessorMock,
                    'customerDataFactory' => $this->customerDataFactoryMock,
                    'itemProcessor' => $this->itemProcessor,
                    'orderIncrementIdChecker' => $this->orderIncrementIdChecker,
                    'data' => [
                        'reserved_order_id' => 1000001,
                    ],
                ]
            );
    }

    /**
     * @param array $addresses
     * @param bool $expected
     * @dataProvider isMultipleShippingAddressesDataProvider
     */
    public function testIsMultipleShippingAddresses($addresses, $expected)
    {
        $this->quoteAddressCollectionMock->expects(
            $this->any()
        )->method(
            'setQuoteFilter'
        )->willReturn(
            $this->quoteAddressCollectionMock
        );
        $this->quoteAddressCollectionMock->expects(
            $this->once()
        )->method(
            'getIterator'
        )->willReturn(
            new \ArrayIterator($addresses)
        );

        $this->assertEquals($expected, $this->quote->isMultipleShippingAddresses());
    }

    /**
     * Customer group ID is not set to quote object and customer data is not available.
     */
    public function testGetCustomerGroupIdNotSet()
    {
        $this->assertEquals(
            \Magento\Customer\Model\GroupManagement::NOT_LOGGED_IN_ID,
            $this->quote->getCustomerGroupId(),
            "Customer group ID is invalid"
        );
    }

    /**
     * Customer group ID is set to quote object.
     */
    public function testGetCustomerGroupId()
    {
        /** Preconditions */
        $customerGroupId = 33;
        $this->quote->setCustomerGroupId($customerGroupId);

        /** SUT execution */
        $this->assertEquals($customerGroupId, $this->quote->getCustomerGroupId(), "Customer group ID is invalid");
    }

    /**
     * @return array
     */
    public function isMultipleShippingAddressesDataProvider()
    {
        return [
            [
                [$this->getAddressMock(Address::TYPE_SHIPPING), $this->getAddressMock(Address::TYPE_SHIPPING)],
                true,
            ],
            [
                [$this->getAddressMock(Address::TYPE_SHIPPING), $this->getAddressMock(Address::TYPE_BILLING)],
                false
            ]
        ];
    }

    /**
     * @param string $type One of \Magento\Customer\Model\Address\AbstractAddress::TYPE_ const
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getAddressMock($type)
    {
        $shippingAddressMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getAddressType', '__wakeup', 'isDeleted']
        );

        $shippingAddressMock->expects($this->any())->method('getAddressType')->willReturn($type);
        $shippingAddressMock->expects($this->any())->method('isDeleted')->willReturn(false);
        return $shippingAddressMock;
    }

    public function testGetStoreIdNoId()
    {
        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getId')
            ->willReturn(null);
        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);

        $result = $this->quote->getStoreId();
        $this->assertNull($result);
    }

    public function testGetStoreId()
    {
        $storeId = 1;

        $result = $this->quote->setStoreId($storeId)->getStoreId();
        $this->assertEquals($storeId, $result);
    }

    public function testGetStore()
    {
        $storeId = 1;

        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->with($storeId)
            ->willReturn($storeMock);

        $this->quote->setStoreId($storeId);
        $result = $this->quote->getStore();
        $this->assertInstanceOf(\Magento\Store\Model\Store::class, $result);
    }

    public function testSetStore()
    {
        $storeId = 1;

        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getId')
            ->willReturn($storeId);

        $result = $this->quote->setStore($storeMock);
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
    }

    public function testGetSharedWebsiteStoreIds()
    {
        $sharedIds = null;
        $storeIds = [1, 2, 3];

        $websiteMock = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $websiteMock->expects($this->once())
            ->method('getStoreIds')
            ->willReturn($storeIds);

        $this->quote->setData('shared_store_ids', $sharedIds);
        $this->quote->setWebsite($websiteMock);
        $result = $this->quote->getSharedStoreIds();
        $this->assertEquals($storeIds, $result);
    }

    public function testGetSharedStoreIds()
    {
        $sharedIds = null;
        $storeIds = [1, 2, 3];
        $storeId = 1;

        $websiteMock = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $websiteMock->expects($this->once())
            ->method('getStoreIds')
            ->willReturn($storeIds);

        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getWebsite')
            ->willReturn($websiteMock);

        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->with($storeId)
            ->willReturn($storeMock);

        $this->quote->setData('shared_store_ids', $sharedIds);
        $this->quote->setStoreId($storeId);
        $result = $this->quote->getSharedStoreIds();
        $this->assertEquals($storeIds, $result);
    }

    public function testLoadActive()
    {
        $quoteId = 1;

        $this->resourceMock->expects($this->once())
            ->method('loadActive')
            ->with($this->quote, $quoteId);

        $this->eventManagerMock->expects($this->any())
            ->method('dispatch');

        $result = $this->quote->loadActive($quoteId);
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
    }

    public function testloadByIdWithoutStore()
    {
        $quoteId = 1;

        $this->resourceMock->expects($this->once())
            ->method('loadByIdWithoutStore')
            ->with($this->quote, $quoteId);

        $this->eventManagerMock->expects($this->any())
            ->method('dispatch');

        $result = $this->quote->loadByIdWithoutStore($quoteId);
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testSetCustomerAddressData()
    {
        $customerId = 1;
        $addressMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\AddressInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getId']
        );
        $addressMock->expects($this->any())
            ->method('getId')
            ->willReturn(null);

        $addresses = [$addressMock];

        $customerMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\CustomerInterface::class,
            [],
            '',
            false
        );
        $customerResultMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\CustomerInterface::class,
            [],
            '',
            false
        );
        $requestMock = $this->createMock(
            \Magento\Framework\DataObject::class
        );

        $this->extensibleDataObjectConverterMock->expects($this->any())
            ->method('toFlatArray')
            ->willReturn(['customer_id' => $customerId]);

        $this->customerRepositoryMock->expects($this->any())
            ->method('getById')
            ->willReturn($customerMock);
        $this->customerDataFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($customerMock);
        $this->customerRepositoryMock->expects($this->never())
            ->method('save')
            ->willReturn($customerMock);
        $customerMock->expects($this->any())
            ->method('getAddresses')
            ->willReturn($addresses);
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->equalTo(['customer_id' => $customerId]))
            ->willReturn($requestMock);
        $result = $this->quote->setCustomerAddressData([$addressMock]);
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
        $this->assertEquals($customerResultMock, $this->quote->getCustomer());
    }

    public function testGetCustomerTaxClassId()
    {
        $groupId = 1;
        $taxClassId = 1;
        $groupMock = $this->getMockForAbstractClass(\Magento\Customer\Api\Data\GroupInterface::class, [], '', false);
        $groupMock->expects($this->once())
            ->method('getTaxClassId')
            ->willReturn($taxClassId);
        $this->groupRepositoryMock->expects($this->once())
            ->method('getById')
            ->with($groupId)
            ->willReturn($groupMock);
        $this->quote->setData('customer_group_id', $groupId);
        $result = $this->quote->getCustomerTaxClassId();
        $this->assertEquals($taxClassId, $result);
    }

    public function testGetAllAddresses()
    {
        $id = 1;
        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);

        $iterator = new \ArrayIterator([$this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quote->setId($id);
        $result = $this->quote->getAllAddresses();
        $this->assertEquals([$this->quoteAddressMock], $result);
    }

    /**
     * @dataProvider dataProviderGetAddress
     */
    public function testGetAddressById($addressId, $expected)
    {
        $id = 1;
        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->once())
            ->method('getId')
            ->willReturn($id);

        $iterator = new \ArrayIterator([$this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quote->setId($id);
        $result = $this->quote->getAddressById($addressId);

        $this->assertEquals((bool)$expected, (bool)$result);
    }

    /**
     * @return array
     */
    public static function dataProviderGetAddress()
    {
        return [
            [1, true],
            [2, false]
        ];
    }

    /**
     * @param $isDeleted
     * @param $customerAddressId
     * @param $expected
     *
     * @dataProvider dataProviderGetAddressByCustomer
     */
    public function testGetAddressByCustomerAddressId($isDeleted, $customerAddressId, $expected)
    {
        $id = 1;
        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn($isDeleted);
        $this->quoteAddressMock->expects($this->once())
            ->method('getCustomerAddressId')
            ->willReturn($customerAddressId);

        $iterator = new \ArrayIterator([$this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quote->setId($id);
        $result = $this->quote->getAddressByCustomerAddressId($id);

        $this->assertEquals((bool)$expected, (bool)$result);
    }

    /**
     * @return array
     */
    public static function dataProviderGetAddressByCustomer()
    {
        return [
            [false, 1, true],
            [false, 2, false]
        ];
    }

    /**
     * @param $isDeleted
     * @param $addressType
     * @param $customerAddressId
     * @param $expected
     *
     * @dataProvider dataProviderShippingAddress
     */
    public function testGetShippingAddressByCustomerAddressId($isDeleted, $addressType, $customerAddressId, $expected)
    {
        $id = 1;

        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn($isDeleted);
        $this->quoteAddressMock->expects($this->once())
            ->method('getCustomerAddressId')
            ->willReturn($customerAddressId);
        $this->quoteAddressMock->expects($this->once())
            ->method('getAddressType')
            ->willReturn($addressType);

        $iterator = new \ArrayIterator([$this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quote->setId($id);

        $result = $this->quote->getShippingAddressByCustomerAddressId($id);
        $this->assertEquals($expected, (bool)$result);
    }

    /**
     * @return array
     */
    public static function dataProviderShippingAddress()
    {
        return [
            [false, \Magento\Customer\Model\Address\AbstractAddress::TYPE_SHIPPING, 1, true],
            [false, \Magento\Customer\Model\Address\AbstractAddress::TYPE_SHIPPING, 2, false],
        ];
    }

    public function testRemoveAddress()
    {
        $id = 1;

        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->once())
            ->method('isDeleted')
            ->with(true);
        $this->quoteAddressMock->expects($this->once())
            ->method('getId')
            ->willReturn($id);

        $iterator = new \ArrayIterator([$this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quote->setId($id);

        $result = $this->quote->removeAddress($id);
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
    }

    public function testRemoveAllAddresses()
    {
        $id = 1;

        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with($id)
            ->willReturnSelf();

        $this->quoteAddressMock->expects($this->any())
            ->method('getAddressType')
            ->willReturn(\Magento\Customer\Model\Address\AbstractAddress::TYPE_SHIPPING);
        $this->quoteAddressMock->expects($this->any())
            ->method('getAddressType')
            ->willReturn(\Magento\Customer\Model\Address\AbstractAddress::TYPE_SHIPPING);
        $this->quoteAddressMock->expects($this->any())
            ->method('isDeleted')
            ->willReturn(false);
        $this->quoteAddressMock->expects($this->any())
            ->method('setData')
            ->willReturnSelf();
        $this->quoteAddressMock->expects($this->once())
            ->method('getId')
            ->willReturn($id);
        $this->quoteAddressMock->expects($this->once())
            ->method('getDeleteImmediately')
            ->willReturn(true);

        $iterator = new \ArrayIterator([$id => $this->quoteAddressMock]);
        $this->quoteAddressCollectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);
        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('removeItemByKey')
            ->with($id)
            ->willReturn($iterator);

        $this->quote->setId($id);

        $result = $this->quote->removeAllAddresses();
        $this->assertInstanceOf(\Magento\Quote\Model\Quote::class, $result);
    }

    /**
     */
    public function testAddProductException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->quote->addProduct($this->productMock, 'test');
    }

    public function testAddProductNoCandidates()
    {
        $expectedResult = 'test_string';
        $requestMock = $this->createMock(
            \Magento\Framework\DataObject::class
        );
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->equalTo(['qty' => 1]))
            ->willReturn($requestMock);

        $this->productMock->expects($this->once())
            ->method('isSalable')
            ->willReturn(true);

        $typeInstanceMock = $this->createPartialMock(\Magento\Catalog\Model\Product\Type\Simple::class, [
                'prepareForCartAdvanced'
            ]);
        $typeInstanceMock->expects($this->once())
            ->method('prepareForCartAdvanced')
            ->willReturn($expectedResult);
        $this->productMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $result = $this->quote->addProduct($this->productMock, null);
        $this->assertEquals($expectedResult, $result);
    }

    public function testAddProductItemPreparation()
    {
        $itemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);

        $expectedResult = $itemMock;
        $requestMock = $this->createMock(
            \Magento\Framework\DataObject::class
        );
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->equalTo(['qty' => 1]))
            ->willReturn($requestMock);

        $typeInstanceMock = $this->createPartialMock(\Magento\Catalog\Model\Product\Type\Simple::class, [
                'prepareForCartAdvanced'
            ]);

        $productMock = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
                'getParentProductId',
                'setStickWithinParent',
                '__wakeup'
            ]);

        $collectionMock = $this->createMock(\Magento\Quote\Model\ResourceModel\Quote\Item\Collection::class);

        $itemMock->expects($this->any())
            ->method('representProduct')
            ->willReturn(true);

        $iterator = new \ArrayIterator([$itemMock]);
        $collectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quoteItemCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $this->productMock->expects($this->once())
            ->method('isSalable')
            ->willReturn(true);

        $typeInstanceMock->expects($this->once())
            ->method('prepareForCartAdvanced')
            ->willReturn([$productMock]);
        $this->productMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $result = $this->quote->addProduct($this->productMock, null);
        $this->assertEquals($expectedResult, $result);
    }

    public function testAddProductItemNew()
    {
        $itemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);

        $expectedResult = $itemMock;
        $requestMock = $this->createMock(
            \Magento\Framework\DataObject::class
        );
        $this->objectFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->equalTo(['qty' => 1]))
            ->willReturn($requestMock);

        $typeInstanceMock = $this->createPartialMock(\Magento\Catalog\Model\Product\Type\Simple::class, [
            'prepareForCartAdvanced'
        ]);

        $productMock = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
            'getParentProductId',
            'setStickWithinParent',
            '__wakeup'
        ]);

        $collectionMock = $this->createMock(\Magento\Quote\Model\ResourceModel\Quote\Item\Collection::class);

        $itemMock->expects($this->any())
            ->method('representProduct')
            ->willReturn(false);

        $iterator = new \ArrayIterator([$itemMock]);
        $collectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);

        $this->quoteItemCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $this->productMock->expects($this->once())
            ->method('isSalable')
            ->willReturn(true);
        $this->itemProcessor
            ->expects($this->once())
            ->method('init')
            ->willReturn($itemMock);
        $itemMock->expects($this->once())
            ->method('setProduct');
        $itemMock->expects($this->once())
            ->method('setOptions');
        $itemMock->expects($this->any())
            ->method('setQuote')
            ->with($this->quote);
        $typeInstanceMock->expects($this->once())
            ->method('prepareForCartAdvanced')
            ->willReturn([$productMock]);
        $this->productMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $result = $this->quote->addProduct($this->productMock, null);
        $this->assertEquals($expectedResult, $result);
    }

    public function testValidateMinimumAmount()
    {
        $storeId = 1;
        $this->quote->setStoreId($storeId);

        $valueMap = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/multi_address', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];
        $this->scopeConfig->expects($this->any())
            ->method('isSetFlag')
            ->willReturnMap($valueMap);

        $this->quoteAddressMock->expects($this->once())
            ->method('validateMinimumAmount')
            ->willReturn(true);

        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->willReturn([$this->quoteAddressMock]);

        $this->assertTrue($this->quote->validateMinimumAmount());
    }

    public function testValidateMinimumAmountNegative()
    {
        $storeId = 1;
        $this->quote->setStoreId($storeId);

        $valueMap = [
            ['sales/minimum_order/active', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/multi_address', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/amount', ScopeInterface::SCOPE_STORE, $storeId, 20],
            ['sales/minimum_order/include_discount_amount', ScopeInterface::SCOPE_STORE, $storeId, true],
            ['sales/minimum_order/tax_including', ScopeInterface::SCOPE_STORE, $storeId, true],
        ];
        $this->scopeConfig->expects($this->any())
            ->method('isSetFlag')
            ->willReturnMap($valueMap);

        $this->quoteAddressMock->expects($this->once())
            ->method('validateMinimumAmount')
            ->willReturn(false);

        $this->quoteAddressCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->willReturn([$this->quoteAddressMock]);

        $this->assertFalse($this->quote->validateMinimumAmount());
    }

    public function testGetPaymentIsNotDeleted()
    {
        $this->quote->setId(1);
        $payment = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Payment::class,
            ['setQuote', 'isDeleted', '__wakeup']
        );
        $payment->expects($this->once())
            ->method('setQuote');
        $payment->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);
        $quotePaymentCollectionMock = $this->createPartialMock(
            \Magento\Quote\Model\ResourceModel\Quote\Payment\Collection::class,
            ['setQuoteFilter', 'getFirstItem']
        );
        $quotePaymentCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with(1)
            ->willReturnSelf();
        $quotePaymentCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($payment);
        $this->quotePaymentCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($quotePaymentCollectionMock);

        $this->assertInstanceOf(\Magento\Quote\Model\Quote\Payment::class, $this->quote->getPayment());
    }

    public function testGetPaymentIsDeleted()
    {
        $this->quote->setId(1);
        $payment = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Payment::class,
            ['setQuote', 'isDeleted', 'getId', '__wakeup']
        );
        $payment->expects($this->exactly(2))
        ->method('setQuote');
        $payment->expects($this->once())
            ->method('isDeleted')
            ->willReturn(true);
        $payment->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $quotePaymentCollectionMock = $this->createPartialMock(
            \Magento\Quote\Model\ResourceModel\Quote\Payment\Collection::class,
            ['setQuoteFilter', 'getFirstItem']
        );
        $quotePaymentCollectionMock->expects($this->once())
            ->method('setQuoteFilter')
            ->with(1)
            ->willReturnSelf();
        $quotePaymentCollectionMock->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($payment);
        $this->quotePaymentCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($quotePaymentCollectionMock);

        $this->paymentFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($payment);

        $this->assertInstanceOf(\Magento\Quote\Model\Quote\Payment::class, $this->quote->getPayment());
    }

    public function testAddItem()
    {
        $item = $this->createPartialMock(\Magento\Quote\Model\Quote\Item::class, ['setQuote', 'getId']);
        $item->expects($this->once())
            ->method('setQuote');
        $item->expects($this->once())
            ->method('getId')
            ->willReturn(false);
        $itemsMock = $this->createPartialMock(
            \Magento\Eav\Model\Entity\Collection\AbstractCollection::class,
            ['setQuote', 'addItem']
        );
        $itemsMock->expects($this->once())
            ->method('setQuote');
        $itemsMock->expects($this->once())
            ->method('addItem')
            ->with($item);
        $this->quoteItemCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($itemsMock);
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch');

        $this->quote->addItem($item);
    }

    /**
     * @param array $productTypes
     * @param int $expected
     * @dataProvider dataProviderForTestBeforeSaveIsVirtualQuote
     */
    public function testBeforeSaveIsVirtualQuote(array $productTypes, $expected)
    {
        $storeId = 1;
        $currencyMock = $this->getMockBuilder(\Magento\Directory\Model\Currency::class)
            ->disableOriginalConstructor()
            ->getMock();
        $currencyMock->expects($this->any())
            ->method('getCode')
            ->willReturn('test_code');
        $currencyMock->expects($this->any())
            ->method('getRate')
            ->willReturn('test_rate');
        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getBaseCurrency')
            ->willReturn($currencyMock);
        $storeMock->expects($this->once())
            ->method('getCurrentCurrency')
            ->willReturn($currencyMock);

        $this->storeManagerMock->expects($this->any())
            ->method('getStore')
            ->with($storeId)
            ->willReturn($storeMock);
        $this->quote->setStoreId($storeId);

        $collectionMock = $this->createMock(\Magento\Quote\Model\ResourceModel\Quote\Item\Collection::class);
        $items = [];
        foreach ($productTypes as $type) {
            $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
            $productMock->expects($this->any())->method('getIsVirtual')->willReturn($type);

            $itemMock = $this->createPartialMock(
                \Magento\Quote\Model\Quote\Item::class,
                ['isDeleted', 'getParentItemId', 'getProduct']
            );
            $itemMock->expects($this->any())
                ->method('isDeleted')
                ->willReturn(false);
            $itemMock->expects($this->any())
                ->method('getParentItemId')
                ->willReturn(false);
            $itemMock->expects($this->any())
                ->method('getProduct')
                ->willReturn($productMock);
            $items[] = $itemMock;
        }
        $iterator = new \ArrayIterator($items);
        $collectionMock->expects($this->any())
            ->method('getIterator')
            ->willReturn($iterator);
        $this->quoteItemCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $this->quote->beforeSave();
        $this->assertEquals($expected, $this->quote->getDataByKey(CartInterface::KEY_IS_VIRTUAL));
        $this->assertNull($this->quote->getUpdatedAt());
    }

    /**
     * @return array
     */
    public function dataProviderForTestBeforeSaveIsVirtualQuote()
    {
        return [
            [[true], 1],
            [[true, true], 1],
            [[false], 0],
            [[true, false], 0],
            [[false, false], 0]
        ];
    }

    public function testGetItemsCollection()
    {
        $itemCollectionMock = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote\Collection::class)
            ->disableOriginalConstructor()
            ->setMethods(['setQuote'])
            ->getMock();
        $this->quoteItemCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($itemCollectionMock);

        $this->extensionAttributesJoinProcessorMock->expects($this->once())
            ->method('process')
            ->with(
                $this->isInstanceOf(\Magento\Quote\Model\ResourceModel\Quote\Collection::class)
            );
        $itemCollectionMock->expects($this->once())->method('setQuote')->with($this->quote);

        $this->quote->getItemsCollection();
    }

    public function testGetAllItems()
    {
        $itemOneMock = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote\Item::class)
            ->setMethods(['isDeleted'])
            ->disableOriginalConstructor()
            ->getMock();
        $itemOneMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);

        $itemTwoMock = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote\Item::class)
            ->setMethods(['isDeleted'])
            ->disableOriginalConstructor()
            ->getMock();
        $itemTwoMock->expects($this->once())
            ->method('isDeleted')
            ->willReturn(true);

        $items = [$itemOneMock, $itemTwoMock];
        $itemResult = [$itemOneMock];
        $this->quote->setData('items_collection', $items);

        $this->assertEquals($itemResult, $this->quote->getAllItems());
    }

    /**
     * Test to verify if existing reserved_order_id in use.
     *
     * @param bool $isReservedOrderIdExist
     * @param int $reservedOrderId
     * @return void
     * @dataProvider reservedOrderIdDataProvider
     */
    public function testReserveOrderId(bool $isReservedOrderIdExist, int $reservedOrderId): void
    {
        $this->orderIncrementIdChecker
            ->expects($this->once())
            ->method('isIncrementIdUsed')
            ->with(1000001)->willReturn($isReservedOrderIdExist);
        $this->resourceMock->expects($this->any())->method('getReservedOrderId')->willReturn($reservedOrderId);
        $this->quote->reserveOrderId();
        $this->assertEquals($reservedOrderId, $this->quote->getReservedOrderId());
    }

    /**
     * @return array
     */
    public function reservedOrderIdDataProvider(): array
    {
        return [
            'id_already_in_use' => [true, 100002],
            'id_not_in_use' => [false, 1000001],
        ];
    }
}
