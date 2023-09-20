<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Quote\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResults;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\CartExtension;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartInterfaceFactory;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\Data\CartSearchResultsInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap as BootstrapHelper;
use Magento\TestFramework\Quote\Model\GetQuoteByReservedOrderId;
use PHPUnit\Framework\TestCase;

/**
 * Test for quote repository
 *
 * @see \Magento\Quote\Model\QuoteRepository
 * @magentoDbIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class QuoteRepositoryTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var GetQuoteByReservedOrderId
     */
    private $getQuoteByReservedOrderId;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var CartInterfaceFactory
     */
    private $quoteFactory;

    /**
     * @var CartItemInterfaceFactory
     */
    private $itemFactory;

    /**
     * @var CartInterface|null
     */
    private $quote;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = BootstrapHelper::getObjectManager();
        $this->quoteRepository = $this->objectManager->create(CartRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->filterBuilder = $this->objectManager->get(FilterBuilder::class);
        $this->getQuoteByReservedOrderId = $this->objectManager->get(GetQuoteByReservedOrderId::class);
        $this->storeRepository = $this->objectManager->get(StoreRepositoryInterface::class);
        $this->addressFactory = $this->objectManager->get(AddressInterfaceFactory::class);
        $this->quoteFactory = $this->objectManager->get(CartInterfaceFactory::class);
        $this->itemFactory = $this->objectManager->get(CartItemInterfaceFactory::class);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        if ($this->quote instanceof CartInterface) {
            $this->quoteRepository->delete($this->quote);
        }

        parent::tearDown();
    }

    /**
     * Tests that quote saved with custom store id has same store id after getting via repository.
     *
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     *
     * @return void
     */
    public function testGetQuoteWithCustomStoreId(): void
    {
        $secondStoreCode = 'fixture_second_store';
        $reservedOrderId = 'test01';
        $secondStore = $this->storeRepository->get($secondStoreCode);
        $quote = $this->getQuoteByReservedOrderId->execute($reservedOrderId);
        $quote->setStoreId($secondStore->getId());
        $this->quoteRepository->save($quote);
        $savedQuote = $this->quoteRepository->get($quote->getId());
        $this->assertEquals(
            $secondStore->getId(),
            $savedQuote->getStoreId(),
            'Quote store id should be equal with store id value in DB'
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     *
     * @return void
     */
    public function testGetList(): void
    {
        $searchCriteria = $this->getSearchCriteria('test01');
        $searchResult = $this->quoteRepository->getList($searchCriteria);
        $this->performAssertions($searchResult);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     *
     * @return void
     */
    public function testGetListDoubleCall(): void
    {
        $searchCriteria1 = $this->getSearchCriteria('test01');
        $searchCriteria2 = $this->getSearchCriteria('test02');
        $searchResult = $this->quoteRepository->getList($searchCriteria1);
        $this->performAssertions($searchResult);
        $searchResult = $this->quoteRepository->getList($searchCriteria2);
        $this->assertEmpty($searchResult->getItems());
    }

    /**
     * @magentoAppIsolation enabled
     *
     * @return void
     */
    public function testSaveWithNotExistingCustomerAddress(): void
    {
        $addressData = include __DIR__ . '/../../Sales/_files/address_data.php';
        $billingAddress = $this->addressFactory->create(['data' => $addressData]);
        $billingAddress->setAddressType(QuoteAddress::ADDRESS_TYPE_BILLING)->setCustomerAddressId('not_existing');
        $shippingAddress = $this->addressFactory->create(['data' => $addressData]);
        $shippingAddress->setAddressType(QuoteAddress::ADDRESS_TYPE_SHIPPING)->setCustomerAddressId('not_existing');
        $shipping = $this->objectManager->get(ShippingInterface::class);
        $shipping->setAddress($shippingAddress);
        $shippingAssignment = $this->objectManager->get(ShippingAssignmentInterface::class);
        $shippingAssignment->setItems([]);
        $shippingAssignment->setShipping($shipping);
        $extensionAttributes = $this->objectManager->get(CartExtension::class);
        $extensionAttributes->setShippingAssignments([$shippingAssignment]);
        $this->quote = $this->quoteFactory->create();
        $this->quote->setStoreId(1)
            ->setIsActive(true)
            ->setIsMultiShipping(0)
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setExtensionAttributes($extensionAttributes)
            ->save();
        $this->quoteRepository->save($this->quote);
        $this->assertNull($this->quote->getBillingAddress()->getCustomerAddressId());
        $this->assertNull(
            $this->quote->getExtensionAttributes()
                ->getShippingAssignments()[0]
                ->getShipping()
                ->getAddress()
                ->getCustomerAddressId()
        );
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     *
     * @return void
     */
    public function testSaveQuoteWithItems(): void
    {
        $items = $this->prepareQuoteItems(['simple1', 'simple2']);
        $this->quote = $this->quoteFactory->create();
        $this->quote->setItems($items);
        $this->quoteRepository->save($this->quote);
        $this->assertCount(2, $this->quote->getItemsCollection());
        $this->assertEquals(2, $this->quote->getItemsCount());
        $this->assertEquals(2, $this->quote->getItemsQty());
    }

    /**
     * Prepare quote items by products sku.
     *
     * @param array $productsSku
     * @return array
     */
    private function prepareQuoteItems(array $productsSku): array
    {
        $items = [];
        foreach ($productsSku as $sku) {
            $item = $this->itemFactory->create();
            $item->setSku($sku)->setQty(1);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Get search criteria
     *
     * @param string $filterValue
     * @return SearchCriteria
     */
    private function getSearchCriteria(string $filterValue): SearchCriteria
    {
        $filters = [];
        $filters[] = $this->filterBuilder->setField('reserved_order_id')
            ->setConditionType('=')
            ->setValue($filterValue)
            ->create();
        $this->searchCriteriaBuilder->addFilters($filters);

        return $this->searchCriteriaBuilder->create();
    }

    /**
     * Perform assertions
     *
     * @param CartSearchResultsInterface $searchResult
     * @return void
     */
    private function performAssertions(CartSearchResultsInterface $searchResult): void
    {
        $expectedExtensionAttributes = [
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'email' => 'admin@example.com',
        ];
        $items = $searchResult->getItems();
        $actualQuote = array_pop($items);
        $testAttribute = $actualQuote->getExtensionAttributes()->getQuoteTestAttribute();
        $this->assertInstanceOf(CartInterface::class, $actualQuote);
        $this->assertEquals('test01', $actualQuote->getReservedOrderId());
        $this->assertEquals($expectedExtensionAttributes['firstname'], $testAttribute->getFirstName());
        $this->assertEquals($expectedExtensionAttributes['lastname'], $testAttribute->getLastName());
        $this->assertEquals($expectedExtensionAttributes['email'], $testAttribute->getEmail());
    }
}
