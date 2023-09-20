<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Quote\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\RequestInterface;

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class QuoteManagementTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $model;

    /**
     * @var \Magento\Quote\Model\SubmitQuoteValidator|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $submitQuoteValidator;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManager;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\ToOrder|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressToOrder;

    /**
     * @var \Magento\Quote\Model\Quote\Payment\ToOrderPayment|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quotePaymentToOrderPayment;

    /**
     * @var \Magento\Quote\Model\Quote\Address\ToOrderAddress|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressToOrderAddress;

    /**
     * @var \Magento\Quote\Model\Quote\Item\ToOrderItem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteItemToOrderItem;

    /**
     * @var \Magento\Quote\Model\Quote\Payment\ToOrderPayment|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderManagement;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteRepositoryMock;

    /**
     * @var CustomerManagement
     */
    protected $customerManagement;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $userContextMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerRepositoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressFactory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $checkoutSessionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSessionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataObjectHelperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $accountManagementMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteIdMock;

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface
     */
    private $addressRepositoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteFactoryMock;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestMock;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress|\PHPUnit\Framework\MockObject\MockObject
     */
    private $remoteAddressMock;

    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $quoteIdMaskFactoryMock;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->submitQuoteValidator = $this->createMock(\Magento\Quote\Model\SubmitQuoteValidator::class);
        $this->eventManager = $this->getMockForAbstractClass(\Magento\Framework\Event\ManagerInterface::class);
        $this->orderFactory = $this->createPartialMock(
            \Magento\Sales\Api\Data\OrderInterfaceFactory::class,
            ['create', 'populate']
        );
        $this->quoteAddressToOrder = $this->createMock(\Magento\Quote\Model\Quote\Address\ToOrder::class);
        $this->quotePaymentToOrderPayment = $this->createMock(\Magento\Quote\Model\Quote\Payment\ToOrderPayment::class);
        $this->quoteAddressToOrderAddress = $this->createMock(\Magento\Quote\Model\Quote\Address\ToOrderAddress::class);
        $this->quoteItemToOrderItem = $this->createMock(\Magento\Quote\Model\Quote\Item\ToOrderItem::class);
        $this->orderManagement = $this->createMock(\Magento\Sales\Api\OrderManagementInterface::class);
        $this->customerManagement = $this->createMock(\Magento\Quote\Model\CustomerManagement::class);
        $this->quoteRepositoryMock = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);

        $this->userContextMock = $this->createMock(\Magento\Authorization\Model\UserContextInterface::class);
        $this->customerRepositoryMock = $this->createPartialMock(
            \Magento\Customer\Api\CustomerRepositoryInterface::class,
            ['create', 'save', 'get', 'getById', 'getList', 'delete', 'deleteById']
        );
        $this->customerFactoryMock = $this->createPartialMock(
            \Magento\Customer\Model\CustomerFactory::class,
            ['create']
        );
        $this->storeManagerMock = $this->getMockForAbstractClass(
            \Magento\Store\Model\StoreManagerInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getStore', 'getStoreId']
        );

        $this->quoteMock = $this->createPartialMock(
            Quote::class,
            [
                'assignCustomer',
                'collectTotals',
                'getBillingAddress',
                'getCheckoutMethod',
                'getPayment',
                'setCheckoutMethod',
                'setCustomerEmail',
                'setCustomerGroupId',
                'setCustomerId',
                'setCustomerIsGuest',
                'setRemoteIp',
                'setXForwardedFor',
                'getId'
            ]
        );

        $this->quoteAddressFactory = $this->createPartialMock(
            \Magento\Quote\Model\Quote\AddressFactory::class,
            ['create']
        );

        $this->dataObjectHelperMock = $this->createMock(\Magento\Framework\Api\DataObjectHelper::class);
        $this->checkoutSessionMock = $this->createPartialMock(
            \Magento\Checkout\Model\Session::class,
            ['setLastQuoteId', 'setLastSuccessQuoteId', 'setLastOrderId', 'setLastRealOrderId', 'setLastOrderStatus']
        );
        $this->customerSessionMock = $this->createMock(\Magento\Customer\Model\Session::class);
        $this->accountManagementMock = $this->createMock(\Magento\Customer\Api\AccountManagementInterface::class);

        $this->quoteFactoryMock = $this->createPartialMock(\Magento\Quote\Model\QuoteFactory::class, ['create']);
        $this->addressRepositoryMock = $this->getMockBuilder(\Magento\Customer\Api\AddressRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->model = $objectManager->getObject(
            \Magento\Quote\Model\QuoteManagement::class,
            [
                'eventManager' => $this->eventManager,
                'submitQuoteValidator' => $this->submitQuoteValidator,
                'orderFactory' => $this->orderFactory,
                'orderManagement' => $this->orderManagement,
                'customerManagement' => $this->customerManagement,
                'quoteAddressToOrder' => $this->quoteAddressToOrder,
                'quoteAddressToOrderAddress' => $this->quoteAddressToOrderAddress,
                'quoteItemToOrderItem' => $this->quoteItemToOrderItem,
                'quotePaymentToOrderPayment' => $this->quotePaymentToOrderPayment,
                'userContext' => $this->userContextMock,
                'quoteRepository' => $this->quoteRepositoryMock,
                'customerRepository' => $this->customerRepositoryMock,
                'customerModelFactory' => $this->customerFactoryMock,
                'quoteAddressFactory' => $this->quoteAddressFactory,
                'dataObjectHelper' => $this->dataObjectHelperMock,
                'storeManager' => $this->storeManagerMock,
                'checkoutSession' => $this->checkoutSessionMock,
                'customerSession' => $this->customerSessionMock,
                'accountManagement' => $this->accountManagementMock,
                'quoteFactory' => $this->quoteFactoryMock,
                'addressRepository' => $this->addressRepositoryMock
            ]
        );

        // Set the new dependency
        $this->quoteIdMock = $this->createMock(\Magento\Quote\Model\QuoteIdMask::class);
        $this->quoteIdMaskFactoryMock = $this->createPartialMock(QuoteIdMaskFactory::class, ['create']);
        $this->setPropertyValue($this->model, 'quoteIdMaskFactory', $this->quoteIdMaskFactoryMock);

        $this->requestMock = $this->createPartialMockForAbstractClass(RequestInterface::class, ['getServer']);
        $this->remoteAddressMock = $this->createMock(RemoteAddress::class);
    }

    public function testCreateEmptyCartAnonymous()
    {
        $storeId = 345;
        $quoteId = 2311;

        $quoteMock = $this->createMock(Quote::class);
        $quoteAddress = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['setCollectShippingRates']
        );
        $quoteAddress->expects($this->once())->method('setCollectShippingRates')->with(true);

        $quoteMock->expects($this->any())->method('setBillingAddress')->with($quoteAddress)->willReturnSelf();
        $quoteMock->expects($this->any())->method('setShippingAddress')->with($quoteAddress)->willReturnSelf();
        $quoteMock->expects($this->any())->method('getShippingAddress')->willReturn($quoteAddress);

        $this->quoteAddressFactory->expects($this->any())->method('create')->willReturn($quoteAddress);

        $this->quoteFactoryMock->expects($this->once())->method('create')->willReturn($quoteMock);
        $quoteMock->expects($this->any())->method('setStoreId')->with($storeId);

        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);
        $quoteMock->expects($this->once())->method('getId')->willReturn($quoteId);

        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturnSelf();
        $this->storeManagerMock->expects($this->once())->method('getStoreId')->willReturn($storeId);

        $this->assertEquals($quoteId, $this->model->createEmptyCart());
    }

    public function testCreateEmptyCartForCustomer()
    {
        $storeId = 345;
        $quoteId = 2311;
        $userId = 567;

        $quoteMock = $this->createMock(Quote::class);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActiveForCustomer')
            ->with($userId)
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
        $customer = $this->getMockBuilder(CustomerInterface::class)
            ->setMethods(['getDefaultBilling'])->disableOriginalConstructor()->getMockForAbstractClass();
        $quoteAddress = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getCustomerId']
        );
        $quoteAddress->expects($this->atLeastOnce())->method('getCustomerId')->willReturn(567);
        $quoteMock->expects($this->atLeastOnce())->method('getBillingAddress')->willReturn($quoteAddress);

        $this->quoteFactoryMock->expects($this->once())->method('create')->willReturn($quoteMock);
        $quoteMock->expects($this->any())->method('setStoreId')->with($storeId);
        $quoteMock->expects($this->any())->method('setCustomerIsGuest')->with(0);

        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);
        $quoteMock->expects($this->once())->method('getId')->willReturn($quoteId);
        $this->customerRepositoryMock->expects($this->atLeastOnce())->method('getById')->willReturn($customer);
        $customer->expects($this->atLeastOnce())->method('getDefaultBilling')->willReturn(0);

        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturnSelf();
        $this->storeManagerMock->expects($this->once())->method('getStoreId')->willReturn($storeId);

        $this->assertEquals($quoteId, $this->model->createEmptyCartForCustomer($userId));
    }

    public function testCreateEmptyCartForCustomerReturnExistsQuote()
    {
        $storeId = 345;
        $userId = 567;

        $quoteMock = $this->createMock(Quote::class);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActiveForCustomer')
            ->with($userId)->willReturn($quoteMock);

        $customer = $this->getMockBuilder(CustomerInterface::class)
            ->setMethods(['getDefaultBilling'])->disableOriginalConstructor()->getMockForAbstractClass();
        $quoteAddress = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getCustomerId']
        );
        $quoteAddress->expects($this->atLeastOnce())->method('getCustomerId')->willReturn(567);
        $quoteMock->expects($this->atLeastOnce())->method('getBillingAddress')->willReturn($quoteAddress);
        $this->customerRepositoryMock->expects($this->atLeastOnce())->method('getById')->willReturn($customer);
        $customer->expects($this->atLeastOnce())->method('getDefaultBilling')->willReturn(0);

        $this->quoteFactoryMock->expects($this->never())->method('create')->willReturn($quoteMock);
        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);

        $this->storeManagerMock->expects($this->once())->method('getStore')->willReturnSelf();
        $this->storeManagerMock->expects($this->once())->method('getStoreId')->willReturn($storeId);

        $this->model->createEmptyCartForCustomer($userId);
    }

    /**
     */
    public function testAssignCustomerFromAnotherStore()
    {
        $this->expectException(\Magento\Framework\Exception\StateException::class);
        $this->expectExceptionMessage('The customer can\'t be assigned to the cart. The cart belongs to a different store.');

        $cartId = 220;
        $customerId = 455;
        $storeId = 5;

        $quoteMock = $this->createMock(Quote::class);
        $customerMock = $this->getMockForAbstractClass(CustomerInterface::class);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($quoteMock);

        $this->customerRepositoryMock
            ->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);

        $customerModelMock = $this->createPartialMock(
            Customer::class,
            ['load', 'getSharedStoreIds']
        );
        $this->customerFactoryMock->expects($this->once())->method('create')->willReturn($customerModelMock);
        $customerModelMock
            ->expects($this->once())
            ->method('load')
            ->with($customerId)
            ->willReturnSelf();

        $customerModelMock
            ->expects($this->once())
            ->method('getSharedStoreIds')
            ->willReturn([]);

        $this->model->assignCustomer($cartId, $customerId, $storeId);
    }

    /**
     */
    public function testAssignCustomerToNonanonymousCart()
    {
        $this->expectException(\Magento\Framework\Exception\StateException::class);
        $this->expectExceptionMessage('The customer can\'t be assigned to the cart because the cart isn\'t anonymous.');

        $cartId = 220;
        $customerId = 455;
        $storeId = 5;

        $quoteMock = $this->createPartialMock(
            Quote::class,
            ['getCustomerId', 'setCustomer', 'setCustomerIsGuest']
        );
        $customerMock = $this->getMockForAbstractClass(CustomerInterface::class);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($quoteMock);

        $this->customerRepositoryMock
            ->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);

        $customerModelMock = $this->createPartialMock(
            Customer::class,
            ['load', 'getSharedStoreIds']
        );
        $this->customerFactoryMock->expects($this->once())->method('create')->willReturn($customerModelMock);
        $customerModelMock
            ->expects($this->once())
            ->method('load')
            ->with($customerId)
            ->willReturnSelf();

        $customerModelMock
            ->expects($this->once())
            ->method('getSharedStoreIds')
            ->willReturn([$storeId, 'some store value']);

        $quoteMock->expects($this->once())->method('getCustomerId')->willReturn(753);

        $this->model->assignCustomer($cartId, $customerId, $storeId);
    }

    /**
     */
    public function testAssignCustomerNoSuchCustomer()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);

        $cartId = 220;
        $customerId = 455;
        $storeId = 5;

        $quoteMock = $this->createPartialMock(
            Quote::class,
            ['getCustomerId', 'setCustomer', 'setCustomerIsGuest']
        );

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($quoteMock);

        $this->customerRepositoryMock
            ->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());

        $this->expectExceptionMessage(
            "No such entity."
        );

        $this->model->assignCustomer($cartId, $customerId, $storeId);
    }

    public function testAssignCustomerWithActiveCart()
    {
        $cartId = 220;
        $customerId = 455;
        $storeId = 5;

        $this->getPropertyValue($this->model, 'quoteIdMaskFactory')
            ->expects($this->once())
            ->method('create')
            ->willReturn($this->quoteIdMock);

        $quoteMock = $this->createPartialMock(
            Quote::class,
            ['getCustomerId', 'setCustomer', 'setCustomerIsGuest', 'setIsActive', 'getIsActive', 'merge']
        );

        $activeQuoteMock = $this->createPartialMock(
            Quote::class,
            ['getCustomerId', 'setCustomer', 'setCustomerIsGuest', 'setIsActive', 'getIsActive', 'merge']
        );

        $customerMock = $this->getMockForAbstractClass(CustomerInterface::class);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($quoteMock);

        $this->customerRepositoryMock
            ->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);

        $customerModelMock = $this->createPartialMock(
            Customer::class,
            ['load', 'getSharedStoreIds']
        );
        $this->customerFactoryMock->expects($this->once())->method('create')->willReturn($customerModelMock);
        $customerModelMock
            ->expects($this->once())
            ->method('load')
            ->with($customerId)
            ->willReturnSelf();

        $customerModelMock
            ->expects($this->once())
            ->method('getSharedStoreIds')
            ->willReturn([$storeId, 'some store value']);

        $quoteMock->expects($this->once())->method('getCustomerId')->willReturn(null);
        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getForCustomer')
            ->with($customerId)
            ->willReturn($activeQuoteMock);

        $quoteMock->expects($this->once())->method('merge')->with($activeQuoteMock)->willReturnSelf();
        $activeQuoteMock->expects($this->once())->method('setIsActive')->with(0);
        $this->quoteRepositoryMock->expects($this->atLeastOnce())->method('save')->with($activeQuoteMock);

        $quoteMock->expects($this->once())->method('setCustomer')->with($customerMock);
        $quoteMock->expects($this->once())->method('setCustomerIsGuest')->with(0);
        $quoteMock->expects($this->once())->method('setIsActive')->with(1);

        $this->quoteIdMock->expects($this->once())->method('load')->with($cartId, 'quote_id')->willReturnSelf();
        $this->quoteIdMock->expects($this->once())->method('getId')->willReturn(10);
        $this->quoteIdMock->expects($this->once())->method('delete');
        $this->quoteRepositoryMock->expects($this->atLeastOnce())->method('save')->with($quoteMock);

        $this->model->assignCustomer($cartId, $customerId, $storeId);
    }

    public function testAssignCustomer()
    {
        $cartId = 220;
        $customerId = 455;
        $storeId = 5;

        $this->getPropertyValue($this->model, 'quoteIdMaskFactory')
            ->expects($this->once())
            ->method('create')
            ->willReturn($this->quoteIdMock);

        $quoteMock = $this->createPartialMock(
            Quote::class,
            ['getCustomerId', 'setCustomer', 'setCustomerIsGuest', 'setIsActive', 'getIsActive', 'merge']
        );

        $customerMock = $this->getMockForAbstractClass(CustomerInterface::class);
        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($quoteMock);

        $this->customerRepositoryMock
            ->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);

        $customerModelMock = $this->createPartialMock(
            Customer::class,
            ['load', 'getSharedStoreIds']
        );

        $this->customerFactoryMock->expects($this->once())->method('create')->willReturn($customerModelMock);

        $customerModelMock
            ->expects($this->once())
            ->method('load')
            ->with($customerId)
            ->willReturnSelf();

        $customerModelMock
            ->expects($this->once())
            ->method('getSharedStoreIds')
            ->willReturn([$storeId, 'some store value']);

        $quoteMock->expects($this->once())->method('getCustomerId')->willReturn(null);

        $this->quoteRepositoryMock
            ->expects($this->once())
            ->method('getForCustomer')
            ->with($customerId)
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());

        $quoteMock->expects($this->never())->method('merge');

        $quoteMock->expects($this->once())->method('setCustomer')->with($customerMock);
        $quoteMock->expects($this->once())->method('setCustomerIsGuest')->with(0);
        $quoteMock->expects($this->once())->method('setIsActive')->with(1);

        $this->quoteIdMock->expects($this->once())->method('load')->with($cartId, 'quote_id')->willReturnSelf();
        $this->quoteIdMock->expects($this->once())->method('getId')->willReturn(10);
        $this->quoteIdMock->expects($this->once())->method('delete');
        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);

        $this->model->assignCustomer($cartId, $customerId, $storeId);
    }

    public function testSubmit()
    {
        $orderData = [];
        $isGuest = true;
        $isVirtual = false;
        $customerId = 1;
        $quoteId = 1;
        $quoteItem = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $billingAddress = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $shippingAddress = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getQuoteId', 'getShippingMethod', 'getId']
        );
        $payment = $this->createMock(\Magento\Quote\Model\Quote\Payment::class);
        $baseOrder = $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class);
        $convertedBilling = $this->createPartialMockForAbstractClass(OrderAddressInterface::class, ['setData']);
        $convertedShipping = $this->createPartialMockForAbstractClass(OrderAddressInterface::class, ['setData']);
        $convertedPayment = $this->createMock(\Magento\Sales\Api\Data\OrderPaymentInterface::class);
        $convertedQuoteItem = $this->createMock(\Magento\Sales\Api\Data\OrderItemInterface::class);

        $addresses = [$convertedShipping, $convertedBilling];
        $quoteItems = [$quoteItem];
        $convertedItems = [$convertedQuoteItem];

        $quote = $this->getQuote(
            $isGuest,
            $isVirtual,
            $billingAddress,
            $payment,
            $customerId,
            $quoteId,
            $quoteItems,
            $shippingAddress
        );

        $this->submitQuoteValidator->expects($this->once())
            ->method('validateQuote')
            ->with($quote);
        $this->quoteAddressToOrder->expects($this->once())
            ->method('convert')
            ->with($shippingAddress, $orderData)
            ->willReturn($baseOrder);
        $this->quoteAddressToOrderAddress->expects($this->at(0))
            ->method('convert')
            ->with(
                $shippingAddress,
                [
                    'address_type' => 'shipping',
                    'email' => 'customer@example.com'
                ]
            )
            ->willReturn($convertedShipping);
        $this->quoteAddressToOrderAddress->expects($this->at(1))
            ->method('convert')
            ->with(
                $billingAddress,
                [
                    'address_type' => 'billing',
                    'email' => 'customer@example.com'
                ]
            )
            ->willReturn($convertedBilling);
        $billingAddress->expects($this->once())->method('getId')->willReturn(4);
        $convertedBilling->expects($this->once())->method('setData')->with('quote_address_id', 4);

        $this->quoteItemToOrderItem->expects($this->once())->method('convert')
            ->with($quoteItem, ['parent_item' => null])
            ->willReturn($convertedQuoteItem);
        $this->quotePaymentToOrderPayment->expects($this->once())->method('convert')->with($payment)
            ->willReturn($convertedPayment);
        $shippingAddress->expects($this->once())->method('getShippingMethod')->willReturn('free');
        $shippingAddress->expects($this->once())->method('getId')->willReturn(5);
        $convertedShipping->expects($this->once())->method('setData')->with('quote_address_id', 5);
        $order = $this->prepareOrderFactory(
            $baseOrder,
            $convertedBilling,
            $addresses,
            $convertedPayment,
            $convertedItems,
            $quoteId,
            $convertedShipping
        );

        $this->orderManagement->expects($this->once())
            ->method('place')
            ->with($order)
            ->willReturn($order);
        $this->eventManager->expects($this->at(0))
            ->method('dispatch')
            ->with('sales_model_service_quote_submit_before', ['order' => $order, 'quote' => $quote]);
        $this->eventManager->expects($this->at(1))
            ->method('dispatch')
            ->with('sales_model_service_quote_submit_success', ['order' => $order, 'quote' => $quote]);
        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quote);
        $this->assertEquals($order, $this->model->submit($quote, $orderData));
    }

    public function testPlaceOrderIfCustomerIsGuest()
    {
        $cartId = 100;
        $orderId = 332;
        $orderIncrementId = 100003332;
        $orderStatus = 'status1';
        $email = 'email@mail.com';

        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($this->quoteMock);

        $this->quoteMock->expects($this->once())
            ->method('getCheckoutMethod')
            ->willReturn(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
        $this->quoteMock->expects($this->once())->method('setCustomerId')->with(null)->willReturnSelf();
        $this->quoteMock->expects($this->once())->method('setCustomerEmail')->with($email)->willReturnSelf();

        $addressMock = $this->createPartialMock(\Magento\Quote\Model\Quote\Address::class, ['getEmail']);
        $addressMock->expects($this->once())->method('getEmail')->willReturn($email);
        $this->quoteMock->expects($this->any())->method('getBillingAddress')->with()->willReturn($addressMock);

        $this->quoteMock->expects($this->once())->method('setCustomerIsGuest')->with(true)->willReturnSelf();
        $this->quoteMock->expects($this->once())
            ->method('setCustomerGroupId')
            ->with(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);

        /** @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Quote\Model\QuoteManagement $service */
        $service = $this->getMockBuilder(\Magento\Quote\Model\QuoteManagement::class)
            ->setMethods(['submit'])
            ->setConstructorArgs(
                [
                    'eventManager' => $this->eventManager,
                    'quoteValidator' => $this->submitQuoteValidator,
                    'orderFactory' => $this->orderFactory,
                    'orderManagement' => $this->orderManagement,
                    'customerManagement' => $this->customerManagement,
                    'quoteAddressToOrder' => $this->quoteAddressToOrder,
                    'quoteAddressToOrderAddress' => $this->quoteAddressToOrderAddress,
                    'quoteItemToOrderItem' => $this->quoteItemToOrderItem,
                    'quotePaymentToOrderPayment' => $this->quotePaymentToOrderPayment,
                    'userContext' => $this->userContextMock,
                    'quoteRepository' => $this->quoteRepositoryMock,
                    'customerRepository' => $this->customerRepositoryMock,
                    'customerModelFactory' => $this->customerFactoryMock,
                    'quoteAddressFactory' => $this->quoteAddressFactory,
                    'dataObjectHelper' => $this->dataObjectHelperMock,
                    'storeManager' => $this->storeManagerMock,
                    'checkoutSession' => $this->checkoutSessionMock,
                    'customerSession' => $this->customerSessionMock,
                    'accountManagement' => $this->accountManagementMock,
                    'quoteFactory' => $this->quoteFactoryMock,
                    'quoteIdMaskFactory' => $this->quoteIdMaskFactoryMock,
                    'addressRepository' => $this->addressRepositoryMock,
                    'request' => $this->requestMock,
                    'remoteAddress' => $this->remoteAddressMock,
                ]
            )
            ->getMock();

        $orderMock = $this->createPartialMock(
            \Magento\Sales\Model\Order::class,
            ['getId', 'getIncrementId', 'getStatus']
        );
        $service->expects($this->once())->method('submit')->willReturn($orderMock);

        $this->quoteMock->expects($this->atLeastOnce())->method('getId')->willReturn($cartId);
        $this->quoteMock->expects($this->once())->method('collectTotals')->willReturnSelf();

        $orderMock->expects($this->atLeastOnce())->method('getId')->willReturn($orderId);
        $orderMock->expects($this->atLeastOnce())->method('getIncrementId')->willReturn($orderIncrementId);
        $orderMock->expects($this->atLeastOnce())->method('getStatus')->willReturn($orderStatus);

        $this->checkoutSessionMock->expects($this->once())->method('setLastQuoteId')->with($cartId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastSuccessQuoteId')->with($cartId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastOrderId')->with($orderId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastRealOrderId')->with($orderIncrementId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastOrderStatus')->with($orderStatus);

        $this->assertEquals($orderId, $service->placeOrder($cartId));
    }

    public function testPlaceOrder()
    {
        $cartId = 323;
        $orderId = 332;
        $orderIncrementId = 100003332;
        $orderStatus = 'status1';
        $remoteAddress = '192.168.1.10';
        $forwardedForIp = '192.168.1.11';

        /** @var \PHPUnit\Framework\MockObject\MockObject|\Magento\Quote\Model\QuoteManagement $service */
        $service = $this->getMockBuilder(\Magento\Quote\Model\QuoteManagement::class)
            ->setMethods(['submit'])
            ->setConstructorArgs(
                [
                'eventManager' => $this->eventManager,
                    'quoteValidator' => $this->submitQuoteValidator,
                    'orderFactory' => $this->orderFactory,
                    'orderManagement' => $this->orderManagement,
                    'customerManagement' => $this->customerManagement,
                    'quoteAddressToOrder' => $this->quoteAddressToOrder,
                    'quoteAddressToOrderAddress' => $this->quoteAddressToOrderAddress,
                    'quoteItemToOrderItem' => $this->quoteItemToOrderItem,
                    'quotePaymentToOrderPayment' => $this->quotePaymentToOrderPayment,
                    'userContext' => $this->userContextMock,
                    'quoteRepository' => $this->quoteRepositoryMock,
                    'customerRepository' => $this->customerRepositoryMock,
                    'customerModelFactory' => $this->customerFactoryMock,
                    'quoteAddressFactory' => $this->quoteAddressFactory,
                    'dataObjectHelper' => $this->dataObjectHelperMock,
                    'storeManager' => $this->storeManagerMock,
                    'checkoutSession' => $this->checkoutSessionMock,
                    'customerSession' => $this->customerSessionMock,
                    'accountManagement' => $this->accountManagementMock,
                    'quoteFactory' => $this->quoteFactoryMock,
                    'quoteIdMaskFactory' => $this->quoteIdMaskFactoryMock,
                    'addressRepository' => $this->addressRepositoryMock,
                    'request' => $this->requestMock,
                    'remoteAddress' => $this->remoteAddressMock,
                ]
            )
            ->getMock();

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);

        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActive')
            ->with($cartId)
            ->willReturn($this->quoteMock);

        $quotePayment = $this->createMock(\Magento\Quote\Model\Quote\Payment::class);
        $quotePayment->expects($this->once())
            ->method('setQuote');
        $quotePayment->expects($this->once())
            ->method('importData');
        $this->quoteMock->expects($this->atLeastOnce())
            ->method('getPayment')
            ->willReturn($quotePayment);

        $this->quoteMock->expects($this->once())
            ->method('getCheckoutMethod')
            ->willReturn(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
        $this->quoteMock->expects($this->never())
            ->method('setCustomerIsGuest')
            ->with(true);

        $this->remoteAddressMock
            ->method('getRemoteAddress')
            ->willReturn($remoteAddress);

        $this->requestMock
            ->method('getServer')
            ->willReturn($forwardedForIp);

        $this->quoteMock->expects($this->once())->method('setRemoteIp')->with($remoteAddress);
        $this->quoteMock->expects($this->once())->method('setXForwardedFor')->with($forwardedForIp);

        $service->expects($this->once())->method('submit')->willReturn($orderMock);

        $this->quoteMock->expects($this->atLeastOnce())->method('getId')->willReturn($cartId);

        $orderMock->expects($this->atLeastOnce())->method('getId')->willReturn($orderId);
        $orderMock->expects($this->atLeastOnce())->method('getIncrementId')->willReturn($orderIncrementId);
        $orderMock->expects($this->atLeastOnce())->method('getStatus')->willReturn($orderStatus);

        $this->checkoutSessionMock->expects($this->once())->method('setLastQuoteId')->with($cartId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastSuccessQuoteId')->with($cartId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastOrderId')->with($orderId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastRealOrderId')->with($orderIncrementId);
        $this->checkoutSessionMock->expects($this->once())->method('setLastOrderStatus')->with($orderStatus);

        $paymentMethod = $this->createPartialMock(\Magento\Quote\Model\Quote\Payment::class, ['setChecks', 'getData']);
        $paymentMethod->expects($this->once())->method('setChecks');
        $paymentMethod->expects($this->once())->method('getData')->willReturn(['additional_data' => []]);

        $this->assertEquals($orderId, $service->placeOrder($cartId, $paymentMethod));
    }

    /**
     * @param $isGuest
     * @param $isVirtual
     * @param \Magento\Quote\Model\Quote\Address $billingAddress
     * @param \Magento\Quote\Model\Quote\Payment $payment
     * @param $customerId
     * @param $id
     * @param array $quoteItems
     * @param \Magento\Quote\Model\Quote\Address|null $shippingAddress
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getQuote(
        $isGuest,
        $isVirtual,
        \Magento\Quote\Model\Quote\Address $billingAddress,
        \Magento\Quote\Model\Quote\Payment $payment,
        $customerId,
        $id,
        array $quoteItems,
        \Magento\Quote\Model\Quote\Address $shippingAddress = null
    ) {
        $quote = $this->createPartialMock(
            Quote::class,
            [
                'setIsActive',
                'getCustomerEmail',
                'getAllVisibleItems',
                'getCustomerIsGuest',
                'isVirtual',
                'getBillingAddress',
                'getShippingAddress',
                'getId',
                'getCustomer',
                'getAllItems',
                'getPayment',
                'reserveOrderId',
                'getCustomerId',
                'addCustomerAddress'
            ]
        );
        $quote->expects($this->once())
            ->method('setIsActive')
            ->with(false);
        $quote->expects($this->any())
            ->method('getAllVisibleItems')
            ->willReturn($quoteItems);
        $quote->expects($this->once())
            ->method('getAllItems')
            ->willReturn($quoteItems);
        $quote->expects($this->once())
            ->method('getCustomerIsGuest')
            ->willReturn($isGuest);
        $quote->expects($this->any())
            ->method('isVirtual')
            ->willReturn($isVirtual);
        if ($shippingAddress) {
            $quote->expects($this->any())
                ->method('getShippingAddress')
                ->willReturn($shippingAddress);
            $shippingAddress->expects($this->any())->method('getQuoteId')->willReturn($id);
        }
        $quote->expects($this->any())
            ->method('getBillingAddress')
            ->willReturn($billingAddress);
        $quote->expects($this->once())
            ->method('getPayment')
            ->willReturn($payment);

        $customer = $this->createPartialMock(
            Customer::class,
            ['getDefaultBilling', 'getId']
        );
        $quote->expects($this->any())->method('getCustomerId')->willReturn($customerId);

        $customer->expects($this->once())
            ->method('getId')
            ->willReturn($customerId);
        $quote->expects($this->atLeastOnce())
            ->method('getCustomerEmail')
            ->willReturn('customer@example.com');
        $quote->expects($this->any())
            ->method('getCustomer')
            ->willReturn($customer);
        $quote->expects($this->once())
            ->method('getId')
            ->willReturn($id);
        $this->customerRepositoryMock->expects($this->any())->method('getById')->willReturn($customer);

        $customer->expects($this->any())->method('getDefaultBilling')->willReturn(1);
        return $quote;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $baseOrder
     * @param OrderAddressInterface $billingAddress
     * @param array $addresses
     * @param $payment
     * @param array $items
     * @param $quoteId
     * @param OrderAddressInterface $shippingAddress
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function prepareOrderFactory(
        \Magento\Sales\Api\Data\OrderInterface $baseOrder,
        OrderAddressInterface $billingAddress,
        array $addresses,
        $payment,
        array $items,
        $quoteId,
        OrderAddressInterface $shippingAddress = null,
        $customerId = null
    ) {
        $order = $this->createPartialMock(
            \Magento\Sales\Model\Order::class,
            [
                'setShippingAddress',
                'getAddressesCollection',
                'getAddresses',
                'getBillingAddress',
                'addAddresses',
                'setBillingAddress',
                'setAddresses',
                'setPayment',
                'setItems',
                'setQuoteId'
            ]
        );

        $this->orderFactory->expects($this->once())
            ->method('create')
            ->willReturn($order);
        $this->orderFactory->expects($this->never())
            ->method('populate')
            ->with($baseOrder);

        if ($shippingAddress) {
            $order->expects($this->once())->method('setShippingAddress')->with($shippingAddress);
        }
        if ($customerId) {
            $this->orderFactory->expects($this->once())
                ->method('setCustomerId')
                ->with($customerId);
        }
        $order->expects($this->any())->method('getAddressesCollection');
        $order->expects($this->any())->method('getAddresses');
        $order->expects($this->any())->method('getBillingAddress')->willReturn(false);
        $order->expects($this->any())->method('addAddresses')->withAnyParameters()->willReturnSelf();
        $order->expects($this->once())->method('setBillingAddress')->with($billingAddress);
        $order->expects($this->once())->method('setAddresses')->with($addresses);
        $order->expects($this->once())->method('setPayment')->with($payment);
        $order->expects($this->once())->method('setItems')->with($items);
        $order->expects($this->once())->method('setQuoteId')->with($quoteId);

        return $order;
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function testGetCartForCustomer()
    {
        $customerId = 100;
        $cartMock = $this->createMock(Quote::class);
        $this->quoteRepositoryMock->expects($this->once())
            ->method('getActiveForCustomer')
            ->with($customerId)
            ->willReturn($cartMock);
        $this->assertEquals($cartMock, $this->model->getCartForCustomer($customerId));
    }

    /**
     * Get any object property value.
     *
     * @param $object
     * @param $property
     * @return mixed
     */
    protected function getPropertyValue($object, $property)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);

        return $reflectionProperty->getValue($object);
    }

    /**
     * Set object property value.
     *
     * @param $object
     * @param $property
     * @param $value
     */
    protected function setPropertyValue(&$object, $property, $value)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);

        return $object;
    }

    /**
     * Test submit for customer
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSubmitForCustomer()
    {
        $orderData = [];
        $isGuest = false;
        $isVirtual = false;
        $customerId = 1;
        $quoteId = 1;
        $quoteItem = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $billingAddress = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $shippingAddress = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getQuoteId', 'getShippingMethod', 'getId', 'exportCustomerAddress']
        );
        $payment = $this->createMock(\Magento\Quote\Model\Quote\Payment::class);
        $baseOrder = $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class);
        $convertedBilling = $this->createPartialMockForAbstractClass(OrderAddressInterface::class, ['setData']);
        $convertedShipping = $this->createPartialMockForAbstractClass(OrderAddressInterface::class, ['setData']);
        $convertedPayment = $this->createMock(\Magento\Sales\Api\Data\OrderPaymentInterface::class);
        $convertedQuoteItem = $this->createMock(\Magento\Sales\Api\Data\OrderItemInterface::class);

        $addresses = [$convertedShipping, $convertedBilling];
        $quoteItems = [$quoteItem];
        $convertedItems = [$convertedQuoteItem];

        $quote = $this->getQuote(
            $isGuest,
            $isVirtual,
            $billingAddress,
            $payment,
            $customerId,
            $quoteId,
            $quoteItems,
            $shippingAddress
        );

        $this->submitQuoteValidator->method('validateQuote')
            ->with($quote);
        $this->quoteAddressToOrder->expects($this->once())
            ->method('convert')
            ->with($shippingAddress, $orderData)
            ->willReturn($baseOrder);
        $this->quoteAddressToOrderAddress->expects($this->at(0))
            ->method('convert')
            ->with(
                $shippingAddress,
                [
                    'address_type' => 'shipping',
                    'email' => 'customer@example.com'
                ]
            )
            ->willReturn($convertedShipping);
        $this->quoteAddressToOrderAddress->expects($this->at(1))
            ->method('convert')
            ->with(
                $billingAddress,
                [
                    'address_type' => 'billing',
                    'email' => 'customer@example.com'
                ]
            )
            ->willReturn($convertedBilling);
        $this->quoteItemToOrderItem->expects($this->once())->method('convert')
            ->with($quoteItem, ['parent_item' => null])
            ->willReturn($convertedQuoteItem);
        $this->quotePaymentToOrderPayment->expects($this->once())->method('convert')->with($payment)
            ->willReturn($convertedPayment);
        $shippingAddress->expects($this->once())->method('getShippingMethod')->willReturn('free');
        $shippingAddress->expects($this->once())->method('getId')->willReturn(5);
        $convertedShipping->expects($this->once())->method('setData')->with('quote_address_id', 5);
        $order = $this->prepareOrderFactory(
            $baseOrder,
            $convertedBilling,
            $addresses,
            $convertedPayment,
            $convertedItems,
            $quoteId,
            $convertedShipping
        );
        $customerAddressMock = $this->getMockBuilder(\Magento\Customer\Api\Data\AddressInterface::class)
            ->getMockForAbstractClass();
        $shippingAddress->expects($this->once())->method('exportCustomerAddress')->willReturn($customerAddressMock);
        $this->addressRepositoryMock->expects($this->once())->method('save')->with($customerAddressMock);
        $quote->expects($this->any())->method('addCustomerAddress')->with($customerAddressMock);
        $billingAddress->expects($this->once())->method('getCustomerId')->willReturn(2);
        $billingAddress->expects($this->once())->method('getSaveInAddressBook')->willReturn(false);
        $billingAddress->expects($this->once())->method('getId')->willReturn(4);
        $convertedBilling->expects($this->once())->method('setData')->with('quote_address_id', 4);
        $this->orderManagement->expects($this->once())
            ->method('place')
            ->with($order)
            ->willReturn($order);
        $this->eventManager->expects($this->at(0))
            ->method('dispatch')
            ->with('sales_model_service_quote_submit_before', ['order' => $order, 'quote' => $quote]);
        $this->eventManager->expects($this->at(1))
            ->method('dispatch')
            ->with('sales_model_service_quote_submit_success', ['order' => $order, 'quote' => $quote]);
        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quote);
        $this->assertEquals($order, $this->model->submit($quote, $orderData));
    }

    /**
     * Get mock for abstract class with methods.
     *
     * @param string $className
     * @param array $methods
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createPartialMockForAbstractClass($className, $methods = [])
    {
        return $this->getMockForAbstractClass(
            $className,
            [],
            '',
            true,
            true,
            true,
            $methods
        );
    }
}
