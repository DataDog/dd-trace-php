<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Checkout\Api;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Test GuestShippingInformationManagement API.
 */
class GuestShippingInformationManagementTest extends TestCase
{
    /**
     * @var GuestShippingInformationManagementInterface
     */
    private $management;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepo;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepo;

    /**
     * @var ShippingInformationInterfaceFactory
     */
    private $shippingFactory;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteria;

    /**
     * @var QuoteIdMaskFactory
     */
    private $maskFactory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->management = $objectManager->get(GuestShippingInformationManagementInterface::class);
        $this->cartRepo = $objectManager->get(CartRepositoryInterface::class);
        $this->customerRepo = $objectManager->get(CustomerRepositoryInterface::class);
        $this->shippingFactory = $objectManager->get(ShippingInformationInterfaceFactory::class);
        $this->searchCriteria = $objectManager->get(SearchCriteriaBuilder::class);
        $this->maskFactory = $objectManager->get(QuoteIdMaskFactory::class);
    }

    /**
     * Test using another address for quote.
     *
     * @param bool $swapShipping Whether to swap shipping or billing addresses.
     * @return void
     *
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Customer/_files/customer_with_addresses.php
     * @dataProvider getAddressesVariation
     *
     */
    public function testDifferentAddresses(bool $swapShipping)
    {
        $this->expectExceptionMessage("The shipping information was unable to be saved. Verify the input data and try again.");
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $carts = $this->cartRepo->getList(
            $this->searchCriteria->addFilter('reserved_order_id', 'test01')->create()
        )->getItems();
        $cart = array_pop($carts);
        $otherCustomer = $this->customerRepo->get('customer_with_addresses@test.com');
        $otherAddresses = $otherCustomer->getAddresses();
        $otherAddress = array_pop($otherAddresses);

        //Setting invalid IDs.
        /** @var ShippingAssignmentInterface $shippingAssignment */
        $shippingAssignment = $cart->getExtensionAttributes()->getShippingAssignments()[0];
        $shippingAddress = $shippingAssignment->getShipping()->getAddress();
        $billingAddress = $cart->getBillingAddress();
        if ($swapShipping) {
            $address = $shippingAddress;
        } else {
            $address = $billingAddress;
        }
        $address->setCustomerAddressId($otherAddress->getId());
        $address->setCustomerId($otherCustomer->getId());
        $address->setId(null);
        /** @var ShippingInformationInterface $shippingInformation */
        $shippingInformation = $this->shippingFactory->create();
        $shippingInformation->setBillingAddress($billingAddress);
        $shippingInformation->setShippingAddress($shippingAddress);
        $shippingInformation->setShippingMethodCode('flatrate');
        /** @var QuoteIdMask $idMask */
        $idMask = $this->maskFactory->create();
        $idMask->load($cart->getId(), 'quote_id');
        $this->management->saveAddressInformation($idMask->getMaskedId(), $shippingInformation);
    }

    /**
     * Different variations for addresses test.
     *
     * @return array
     */
    public function getAddressesVariation(): array
    {
        return [
            'Shipping address swap' => [true],
            'Billing address swap' => [false]
        ];
    }
}
