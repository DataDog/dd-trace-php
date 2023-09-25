<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Test\Unit\Observer\Frontend\Quote\Address;

/**
 * Class CollectTotalsTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CollectTotalsObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Observer\Frontend\Quote\Address\CollectTotalsObserver
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerAddressMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSession;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerVatMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $addressRepository;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteAddressMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeId;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $vatValidatorMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $observerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerDataFactoryMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $groupManagementMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $groupInterfaceMock;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->storeId = 1;
        $this->customerMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\CustomerInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getStoreId', 'getCustomAttribute', 'getId', '__wakeup']
        );
        $this->customerAddressMock = $this->createMock(\Magento\Customer\Helper\Address::class);
        $this->customerVatMock = $this->createMock(\Magento\Customer\Model\Vat::class);
        $this->customerDataFactoryMock = $this->createPartialMock(
            \Magento\Customer\Api\Data\CustomerInterfaceFactory::class,
            ['mergeDataObjectWithArray', 'create']
        );
        $this->vatValidatorMock = $this->createMock(\Magento\Quote\Observer\Frontend\Quote\Address\VatValidator::class);
        $this->observerMock = $this->createPartialMock(
            \Magento\Framework\Event\Observer::class,
            ['getShippingAssignment', 'getQuote']
        );

        $this->quoteAddressMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getCountryId', 'getVatId', 'getQuote', 'setPrevQuoteCustomerGroupId', '__wakeup']
        );

        $this->quoteMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote::class,
            ['setCustomerGroupId', 'getCustomerGroupId', 'getCustomer', '__wakeup', 'setCustomer']
        );

        $this->groupManagementMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\GroupManagementInterface::class,
            [],
            '',
            false,
            true,
            true,
            [
                'getDefaultGroup',
                'getNotLoggedInGroup'
            ]
        );

        $this->groupInterfaceMock = $this->getMockForAbstractClass(
            \Magento\Customer\Api\Data\GroupInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getId']
        );

        $shippingAssignmentMock = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingMock = $this->createMock(\Magento\Quote\Api\Data\ShippingInterface::class);
        $shippingAssignmentMock->expects($this->once())->method('getShipping')->willReturn($shippingMock);
        $shippingMock->expects($this->once())->method('getAddress')->willReturn($this->quoteAddressMock);

        $this->observerMock->expects($this->once())
            ->method('getShippingAssignment')
            ->willReturn($shippingAssignmentMock);

        $this->observerMock->expects($this->once())->method('getQuote')->willReturn($this->quoteMock);
        $this->quoteMock->expects($this->any())
            ->method('getCustomer')
            ->willReturn($this->customerMock);

        $this->addressRepository = $this->createMock(\Magento\Customer\Api\AddressRepositoryInterface::class);
        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->customerMock->expects($this->any())->method('getStoreId')->willReturn($this->storeId);

        $this->model = new \Magento\Quote\Observer\Frontend\Quote\Address\CollectTotalsObserver(
            $this->customerAddressMock,
            $this->customerVatMock,
            $this->vatValidatorMock,
            $this->customerDataFactoryMock,
            $this->groupManagementMock,
            $this->addressRepository,
            $this->customerSession
        );
    }

    public function testDispatchWithDisableVatValidator()
    {
        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(false);
        $this->model->execute($this->observerMock);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testDispatchWithCustomerCountryNotInEUAndNotLoggedCustomerInGroup()
    {
        $this->groupManagementMock->expects($this->once())
            ->method('getNotLoggedInGroup')
            ->willReturn($this->groupInterfaceMock);
        $this->groupInterfaceMock->expects($this->once())
            ->method('getId')->willReturn(null);
        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(true);

        $this->quoteAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('customerCountryCode');
        $this->quoteAddressMock->expects($this->once())->method('getVatId')->willReturn('vatId');

        $this->customerVatMock->expects(
            $this->once()
        )->method(
            'isCountryInEU'
        )->with(
            'customerCountryCode'
        )->willReturn(
            false
        );

        $this->customerMock->expects($this->once())->method('getId')->willReturn(null);

        /** Assertions */
        $this->quoteAddressMock->expects($this->never())->method('setPrevQuoteCustomerGroupId');
        $this->customerDataFactoryMock->expects($this->never())->method('mergeDataObjectWithArray');
        $this->quoteMock->expects($this->never())->method('setCustomerGroupId');

        /** SUT execution */
        $this->model->execute($this->observerMock);
    }

    public function testDispatchWithDefaultCustomerGroupId()
    {
        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(true);

        $this->quoteAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('customerCountryCode');
        $this->quoteAddressMock->expects($this->once())->method('getVatId')->willReturn(null);

        $this->quoteMock->expects($this->once())
            ->method('getCustomerGroupId')
            ->willReturn('customerGroupId');
        $this->customerMock->expects($this->once())->method('getId')->willReturn('1');
        $this->groupManagementMock->expects($this->once())
            ->method('getDefaultGroup')
            ->willReturn($this->groupInterfaceMock);
        $this->groupInterfaceMock->expects($this->once())
            ->method('getId')->willReturn('defaultCustomerGroupId');
        /** Assertions */
        $this->quoteAddressMock->expects($this->once())
            ->method('setPrevQuoteCustomerGroupId')
            ->with('customerGroupId');
        $this->quoteMock->expects($this->once())->method('setCustomerGroupId')->with('defaultCustomerGroupId');
        $this->customerDataFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->customerMock);

        $this->quoteMock->expects($this->once())->method('setCustomer')->with($this->customerMock);

        /** SUT execution */
        $this->model->execute($this->observerMock);
    }

    public function testDispatchWithCustomerCountryInEU()
    {
        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(true);

        $this->quoteAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('customerCountryCode');
        $this->quoteAddressMock->expects($this->once())
            ->method('getVatId')
            ->willReturn('vatID');

        $this->customerVatMock->expects($this->once())
            ->method('isCountryInEU')
            ->with('customerCountryCode')
            ->willReturn(true);

        $this->quoteMock->expects($this->once())
            ->method('getCustomerGroupId')
            ->willReturn('customerGroupId');

        $validationResult = ['some' => 'result'];
        $this->vatValidatorMock->expects($this->once())
            ->method('validate')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn($validationResult);

        $this->customerVatMock->expects($this->once())
            ->method('getCustomerGroupIdBasedOnVatNumber')
            ->with('customerCountryCode', $validationResult, $this->storeId)
            ->willReturn('customerGroupId');

        /** Assertions */
        $this->quoteAddressMock->expects($this->once())
            ->method('setPrevQuoteCustomerGroupId')
            ->with('customerGroupId');

        $this->quoteMock->expects($this->once())->method('setCustomerGroupId')->with('customerGroupId');
        $this->quoteMock->expects($this->once())->method('setCustomer')->with($this->customerMock);
        $this->customerDataFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->customerMock);
        $this->model->execute($this->observerMock);
    }

    public function testDispatchWithAddressCustomerVatIdAndCountryId()
    {
        $customerCountryCode = "BE";
        $customerVat = "123123123";
        $defaultShipping = 1;

        $customerAddress = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $customerAddress->expects($this->any())
            ->method("getVatId")
            ->willReturn($customerVat);

        $customerAddress->expects($this->any())
            ->method("getCountryId")
            ->willReturn($customerCountryCode);

        $this->addressRepository->expects($this->once())
            ->method("getById")
            ->with($defaultShipping)
            ->willReturn($customerAddress);

        $this->customerMock->expects($this->atLeastOnce())
            ->method("getDefaultShipping")
            ->willReturn($defaultShipping);

        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(true);

        $this->customerVatMock->expects($this->once())
            ->method('isCountryInEU')
            ->with($customerCountryCode)
            ->willReturn(true);

        $this->model->execute($this->observerMock);
    }

    public function testDispatchWithEmptyShippingAddress()
    {
        $customerCountryCode = "DE";
        $customerVat = "123123123";
        $defaultShipping = 1;

        $customerAddress = $this->createMock(\Magento\Customer\Api\Data\AddressInterface::class);
        $customerAddress->expects($this->once())
            ->method("getCountryId")
            ->willReturn($customerCountryCode);

        $customerAddress->expects($this->once())
            ->method("getVatId")
            ->willReturn($customerVat);
        $this->addressRepository->expects($this->once())
            ->method("getById")
            ->with($defaultShipping)
            ->willReturn($customerAddress);

        $this->customerMock->expects($this->atLeastOnce())
            ->method("getDefaultShipping")
            ->willReturn($defaultShipping);

        $this->vatValidatorMock->expects($this->once())
            ->method('isEnabled')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn(true);

        $this->quoteAddressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn(null);
        $this->quoteAddressMock->expects($this->once())
            ->method('getVatId')
            ->willReturn(null);

        $this->customerVatMock->expects($this->once())
            ->method('isCountryInEU')
            ->with($customerCountryCode)
            ->willReturn(true);

        $this->quoteMock->expects($this->once())
            ->method('getCustomerGroupId')
            ->willReturn('customerGroupId');
        $validationResult = ['some' => 'result'];
        $this->customerVatMock->expects($this->once())
            ->method('getCustomerGroupIdBasedOnVatNumber')
            ->with($customerCountryCode, $validationResult, $this->storeId)
            ->willReturn('customerGroupId');
        $this->customerSession->expects($this->once())
            ->method("setCustomerGroupId")
            ->with('customerGroupId');

        $this->vatValidatorMock->expects($this->once())
            ->method('validate')
            ->with($this->quoteAddressMock, $this->storeId)
            ->willReturn($validationResult);

        /** Assertions */
        $this->quoteAddressMock->expects($this->once())
            ->method('setPrevQuoteCustomerGroupId')
            ->with('customerGroupId');

        $this->quoteMock->expects($this->once())->method('setCustomerGroupId')->with('customerGroupId');
        $this->quoteMock->expects($this->once())->method('setCustomer')->with($this->customerMock);
        $this->customerDataFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->customerMock);
        $this->model->execute($this->observerMock);
    }
}
