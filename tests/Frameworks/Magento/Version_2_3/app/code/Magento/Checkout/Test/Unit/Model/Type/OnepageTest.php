<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Checkout\Test\Unit\Model\Type;

use \Magento\Checkout\Model\Type\Onepage;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OnepageTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Checkout\Model\Type\Onepage */
    protected $onepage;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $eventManagerMock;

    /** @var \Magento\Checkout\Helper\Data|\PHPUnit\Framework\MockObject\MockObject */
    protected $checkoutHelperMock;

    /** @var \Magento\Customer\Model\Url|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerUrlMock;

    /** @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $loggerMock;

    /** @var \Magento\Checkout\Model\Session|\PHPUnit\Framework\MockObject\MockObject */
    protected $checkoutSessionMock;

    /** @var \Magento\Customer\Model\Session|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerSessionMock;

    /** @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $storeManagerMock;

    /** @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject */
    protected $requestMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $addressFactoryMock;

    /** @var \Magento\Customer\Model\FormFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerFormFactoryMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $customerFactoryMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $quoteManagementMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $orderFactoryMock;

    /** @var \Magento\Framework\DataObject\Copy|\PHPUnit\Framework\MockObject\MockObject */
    protected $copyMock;

    /** @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $messageManagerMock;

    /** @var \Magento\Customer\Model\Metadata\FormFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $formFactoryMock;

    /** @var \Magento\Customer\Api\Data\CustomerInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerDataFactoryMock;

    /** @var \Magento\Framework\Math\Random|\PHPUnit\Framework\MockObject\MockObject */
    protected $randomMock;

    /** @var \Magento\Framework\Encryption\EncryptorInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $encryptorMock;

    /** @var \Magento\Customer\Api\AddressRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $addressRepositoryMock;

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerRepositoryMock;

    /** @var \Magento\Quote\Api\CartRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $quoteRepositoryMock;

    /**
     * @var \Magento\Customer\Api\AccountManagementInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $accountManagementMock;

    /** @var \Magento\Framework\Api\ExtensibleDataObjectConverter|\PHPUnit\Framework\MockObject\MockObject */
    protected $extensibleDataObjectConverterMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    protected $totalsCollectorMock;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->addressRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\AddressRepositoryInterface::class,
            ['get'],
            '',
            false
        );
        $this->accountManagementMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\AccountManagementInterface::class,
            [],
            '',
            false
        );
        $this->eventManagerMock = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $this->checkoutHelperMock = $this->createMock(\Magento\Checkout\Helper\Data::class);
        $this->customerUrlMock = $this->createMock(\Magento\Customer\Model\Url::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->checkoutSessionMock = $this->createPartialMock(
            \Magento\Checkout\Model\Session::class,
            ['getLastOrderId', 'getQuote', 'setStepData', 'getStepData']
        );
        $this->customerSessionMock = $this->createPartialMock(
            \Magento\Customer\Model\Session::class,
            ['getCustomerDataObject', 'isLoggedIn']
        );
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->requestMock = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()->getMock();
        $this->addressFactoryMock = $this->createMock(\Magento\Customer\Model\AddressFactory::class);
        $this->formFactoryMock = $this->createMock(\Magento\Customer\Model\Metadata\FormFactory::class);
        $this->customerFactoryMock = $this->createMock(\Magento\Customer\Model\CustomerFactory::class);
        $this->quoteManagementMock = $this->createMock(\Magento\Quote\Api\CartManagementInterface::class);
        $this->orderFactoryMock = $this->createPartialMock(\Magento\Sales\Model\OrderFactory::class, ['create']);
        $this->copyMock = $this->createMock(\Magento\Framework\DataObject\Copy::class);
        $this->messageManagerMock = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);

        $this->customerFormFactoryMock = $this->createPartialMock(
            \Magento\Customer\Model\FormFactory::class,
            ['create']
        );

        $this->customerDataFactoryMock = $this->createMock(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);

        $this->randomMock = $this->createMock(\Magento\Framework\Math\Random::class);
        $this->encryptorMock = $this->createMock(\Magento\Framework\Encryption\EncryptorInterface::class);

        $this->customerRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\CustomerRepositoryInterface::class,
            [],
            '',
            false
        );

        $orderSenderMock = $this->createMock(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);

        $this->quoteRepositoryMock = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);

        $this->extensibleDataObjectConverterMock = $this->getMockBuilder(
            \Magento\Framework\Api\ExtensibleDataObjectConverter::class
        )->setMethods(['toFlatArray'])->disableOriginalConstructor()->getMock();

        $this->extensibleDataObjectConverterMock
            ->expects($this->any())
            ->method('toFlatArray')
            ->willReturn([]);
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->totalsCollectorMock = $this->createMock(\Magento\Quote\Model\Quote\TotalsCollector::class);
        $this->onepage = $this->objectManagerHelper->getObject(
            \Magento\Checkout\Model\Type\Onepage::class,
            [
                'eventManager' => $this->eventManagerMock,
                'helper' => $this->checkoutHelperMock,
                'customerUrl' => $this->customerUrlMock,
                'logger' => $this->loggerMock,
                'checkoutSession' => $this->checkoutSessionMock,
                'customerSession' => $this->customerSessionMock,
                'storeManager' => $this->storeManagerMock,
                'request' => $this->requestMock,
                'customrAddrFactory' => $this->addressFactoryMock,
                'customerFormFactory' => $this->customerFormFactoryMock,
                'customerFactory' => $this->customerFactoryMock,
                'orderFactory' => $this->orderFactoryMock,
                'objectCopyService' => $this->copyMock,
                'messageManager' => $this->messageManagerMock,
                'formFactory' => $this->formFactoryMock,
                'customerDataFactory' => $this->customerDataFactoryMock,
                'mathRandom' => $this->randomMock,
                'encryptor' => $this->encryptorMock,
                'addressRepository' => $this->addressRepositoryMock,
                'accountManagement' => $this->accountManagementMock,
                'orderSenderMock' => $orderSenderMock,
                'customerRepository' => $this->customerRepositoryMock,
                'extensibleDataObjectConverter' => $this->extensibleDataObjectConverterMock,
                'quoteRepository' => $this->quoteRepositoryMock,
                'quoteManagement' => $this->quoteManagementMock,
                'totalsCollector' => $this->totalsCollectorMock
            ]
        );
    }

    public function testGetQuote()
    {
        $returnValue = 'ababagalamaga';
        $this->checkoutSessionMock->expects($this->once())->method('getQuote')->willReturn($returnValue);
        $this->assertEquals($returnValue, $this->onepage->getQuote());
    }

    public function testSetQuote()
    {
        /** @var \Magento\Quote\Model\Quote $quoteMock */
        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $this->onepage->setQuote($quoteMock);
        $this->assertEquals($quoteMock, $this->onepage->getQuote());
    }

    /**
     * @dataProvider initCheckoutDataProvider
     */
    public function testInitCheckout($stepData, $isLoggedIn, $isSetStepDataCalled)
    {
        $customer = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\CustomerInterface::class,
            [],
            '',
            false
        );
        /** @var \Magento\Quote\Model\Quote|\PHPUnit\Framework\MockObject\MockObject $quoteMock */
        $quoteMock = $this->createPartialMock(\Magento\Quote\Model\Quote::class, [
                'isMultipleShippingAddresses',
                'removeAllAddresses',
                'save',
                'assignCustomer',
                'getData',
                'getCustomerId',
                '__wakeup',
                'getBillingAddress',
                'setPasswordHash',
                'getCheckoutMethod',
                'isVirtual',
                'getShippingAddress',
                'getCustomerData',
                'collectTotals',
            ]);
        $quoteMock->expects($this->once())->method('isMultipleShippingAddresses')->willReturn(true);
        $quoteMock->expects($this->once())->method('removeAllAddresses');
        $quoteMock->expects($this->once())->method('assignCustomer')->with($customer);

        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);

        $this->customerSessionMock
            ->expects($this->once())
            ->method('getCustomerDataObject')
            ->willReturn($customer);
        $this->customerSessionMock->expects($this->any())->method('isLoggedIn')->willReturn($isLoggedIn);
        $this->checkoutSessionMock->expects($this->once())->method('getQuote')->willReturn($quoteMock);
        $this->checkoutSessionMock->expects($this->any())->method('getStepData')->willReturn($stepData);

        if ($isSetStepDataCalled) {
            $this->checkoutSessionMock->expects($this->once())
                ->method('setStepData')
                ->with(key($stepData), 'allow', false);
        } else {
            $this->checkoutSessionMock->expects($this->never())->method('setStepData');
        }

        $this->onepage->initCheckout();
    }

    /**
     * @return array
     */
    public function initCheckoutDataProvider()
    {
        return [
            [['login' => ''], false, false],
            [['someStep' => ''], true, true],
            [['billing' => ''], true, false],
        ];
    }

    /**
     * @dataProvider getCheckoutMethodDataProvider
     */
    public function testGetCheckoutMethod($isLoggedIn, $quoteCheckoutMethod, $isAllowedGuestCheckout, $expected)
    {
        $this->customerSessionMock->expects($this->once())->method('isLoggedIn')->willReturn($isLoggedIn);
        /** @var \Magento\Quote\Model\Quote|\PHPUnit\Framework\MockObject\MockObject $quoteMock */
        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quoteMock->expects($this->any())->method('setCheckoutMethod')->with($expected);

        $quoteMock->expects($this->any())
            ->method('getCheckoutMethod')
            ->willReturn($quoteCheckoutMethod);

        $this->checkoutHelperMock
            ->expects($this->any())
            ->method('isAllowedGuestCheckout')
            ->willReturn($isAllowedGuestCheckout);

        $this->onepage->setQuote($quoteMock);
        $this->assertEquals($expected, $this->onepage->getCheckoutMethod());
    }

    /**
     * @return array
     */
    public function getCheckoutMethodDataProvider()
    {
        return [
            // isLoggedIn(), getQuote()->getCheckoutMethod(), isAllowedGuestCheckout(), expected
            [true, null, false, Onepage::METHOD_CUSTOMER],
            [false, 'something else', false, 'something else'],
            [false, Onepage::METHOD_GUEST, true, Onepage::METHOD_GUEST],
            [false, Onepage::METHOD_REGISTER, false, Onepage::METHOD_REGISTER],
        ];
    }

    public function testSaveCheckoutMethod()
    {
        $this->assertEquals(['error' => -1, 'message' => 'Invalid data'], $this->onepage->saveCheckoutMethod(null));
        /** @var \Magento\Quote\Model\Quote|\PHPUnit\Framework\MockObject\MockObject $quoteMock */
        $quoteMock = $this->createPartialMock(\Magento\Quote\Model\Quote::class, ['setCheckoutMethod', '__wakeup']);
        $quoteMock->expects($this->once())->method('setCheckoutMethod')->with('someMethod')->willReturnSelf();
        $this->quoteRepositoryMock->expects($this->once())->method('save')->with($quoteMock);
        $this->checkoutSessionMock->expects($this->once())->method('setStepData')->with('billing', 'allow', true);
        $this->onepage->setQuote($quoteMock);
        $this->assertEquals([], $this->onepage->saveCheckoutMethod('someMethod'));
    }

    public function testGetLastOrderId()
    {
        $orderIncrementId = 100001;
        $orderId = 1;
        $this->checkoutSessionMock->expects($this->once())->method('getLastOrderId')
            ->willReturn($orderId);
        $orderMock = $this->createPartialMock(
            \Magento\Sales\Model\Order::class,
            ['load', 'getIncrementId', '__wakeup']
        );
        $orderMock->expects($this->once())->method('load')->with($orderId)->willReturnSelf();
        $orderMock->expects($this->once())->method('getIncrementId')->willReturn($orderIncrementId);
        $this->orderFactoryMock->expects($this->once())->method('create')->willReturn($orderMock);
        $this->assertEquals($orderIncrementId, $this->onepage->getLastOrderId());
    }
}
