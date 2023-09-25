<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Quote\Customer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Test for updating shopping cart items
 */
class UpdateCartItemsTest extends GraphQlAbstract
{
    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @var QuoteResource
     */
    private $quoteResource;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private $quoteIdToMaskedId;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->quoteResource = $objectManager->get(QuoteResource::class);
        $this->quoteFactory = $objectManager->get(QuoteFactory::class);
        $this->quoteIdToMaskedId = $objectManager->get(QuoteIdToMaskedQuoteIdInterface::class);
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
    }

    /**
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     */
    public function testUpdateCartItemQuantity()
    {
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, 'test_order_1', 'reserved_order_id');
        $maskedQuoteId = $this->quoteIdToMaskedId->execute((int)$quote->getId());
        $itemId = (int)$quote->getItemByProduct($this->productRepository->get('simple'))->getId();
        $quantity = 2;

        $query = $this->getQuery($maskedQuoteId, $itemId, $quantity);
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());

        $this->assertArrayHasKey('updateCartItems', $response);
        $this->assertArrayHasKey('cart', $response['updateCartItems']);

        $responseCart = $response['updateCartItems']['cart'];
        $item = current($responseCart['items']);

        $this->assertEquals($itemId, $item['id']);
        $this->assertEquals($quantity, $item['quantity']);
    }

    /**
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     */
    public function testRemoveCartItemIfQuantityIsZero()
    {
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, 'test_order_1', 'reserved_order_id');
        $maskedQuoteId = $this->quoteIdToMaskedId->execute((int)$quote->getId());
        $itemId = (int)$quote->getItemByProduct($this->productRepository->get('simple'))->getId();
        $quantity = 0;

        $query = $this->getQuery($maskedQuoteId, $itemId, $quantity);
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());

        $this->assertArrayHasKey('updateCartItems', $response);
        $this->assertArrayHasKey('cart', $response['updateCartItems']);

        $responseCart = $response['updateCartItems']['cart'];
        $this->assertCount(0, $responseCart['items']);
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     */
    public function testUpdateItemInNonExistentCart()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not find a cart with ID "non_existent_masked_id"');

        $query = $this->getQuery('non_existent_masked_id', 1, 2);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     */
    public function testUpdateNonExistentItem()
    {
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, 'test_order_1', 'reserved_order_id');
        $maskedQuoteId = $this->quoteIdToMaskedId->execute((int)$quote->getId());
        $notExistentItemId = 999;

        $this->expectExceptionMessage("Could not find cart item with id: {$notExistentItemId}.");

        $query = $this->getQuery($maskedQuoteId, $notExistentItemId, 2);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_virtual_product_saved.php
     */
    public function testUpdateItemIfItemIsNotBelongToCart()
    {
        $firstQuote = $this->quoteFactory->create();
        $this->quoteResource->load($firstQuote, 'test_order_1', 'reserved_order_id');
        $firstQuoteMaskedId = $this->quoteIdToMaskedId->execute((int)$firstQuote->getId());

        $secondQuote = $this->quoteFactory->create();
        $this->quoteResource->load(
            $secondQuote,
            'test_order_with_virtual_product_without_address',
            'reserved_order_id'
        );
        $secondQuote->setCustomerId(1);
        $this->quoteResource->save($secondQuote);
        $secondQuoteItemId = (int)$secondQuote
            ->getItemByProduct($this->productRepository->get('virtual-product'))
            ->getId();

        $this->expectExceptionMessage("Could not find cart item with id: {$secondQuoteItemId}.");

        $query = $this->getQuery($firstQuoteMaskedId, $secondQuoteItemId, 2);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_virtual_product_saved.php
     */
    public function testUpdateItemInGuestCart()
    {
        $guestQuote = $this->quoteFactory->create();
        $this->quoteResource->load(
            $guestQuote,
            'test_order_with_virtual_product_without_address',
            'reserved_order_id'
        );
        $guestQuoteMaskedId = $this->quoteIdToMaskedId->execute((int)$guestQuote->getId());
        $guestQuoteItemId = (int)$guestQuote
            ->getItemByProduct($this->productRepository->get('virtual-product'))
            ->getId();

        $this->expectExceptionMessage(
            "The current user cannot perform operations on cart \"$guestQuoteMaskedId\""
        );

        $query = $this->getQuery($guestQuoteMaskedId, $guestQuoteItemId, 2);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/three_customers.php
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_virtual_product_saved.php
     */
    public function testUpdateItemInAnotherCustomerCart()
    {
        $anotherCustomerQuote = $this->quoteFactory->create();
        $this->quoteResource->load(
            $anotherCustomerQuote,
            'test_order_with_virtual_product_without_address',
            'reserved_order_id'
        );
        $anotherCustomerQuote->setCustomerId(2);
        $this->quoteResource->save($anotherCustomerQuote);

        $anotherCustomerQuoteMaskedId = $this->quoteIdToMaskedId->execute((int)$anotherCustomerQuote->getId());
        $anotherCustomerQuoteItemId = (int)$anotherCustomerQuote
            ->getItemByProduct($this->productRepository->get('virtual-product'))
            ->getId();

        $this->expectExceptionMessage(
            "The current user cannot perform operations on cart \"$anotherCustomerQuoteMaskedId\""
        );

        $query = $this->getQuery($anotherCustomerQuoteMaskedId, $anotherCustomerQuoteItemId, 2);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @param string $input
     * @param string $message
     * @dataProvider dataProviderUpdateWithMissedRequiredParameters
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_address_saved.php
     */
    public function testUpdateWithMissedItemRequiredParameters(string $input, string $message)
    {
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, 'test_order_1', 'reserved_order_id');
        $maskedQuoteId = $this->quoteIdToMaskedId->execute((int)$quote->getId());

        $query = <<<QUERY
mutation {
  updateCartItems(input: {
    cart_id: "{$maskedQuoteId}"
    {$input}
  }) {
    cart {
      items {
        id
        quantity
      }
    }
  }
}
QUERY;
        $this->expectExceptionMessage($message);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @return array
     */
    public function dataProviderUpdateWithMissedRequiredParameters(): array
    {
        return [
            'missed_cart_item_qty' => [
                'cart_items: [{ cart_item_id: 1 }]',
                'Required parameter "quantity" for "cart_items" is missing.'
            ],
        ];
    }

    /**
     * @param string $maskedQuoteId
     * @param int $itemId
     * @param float $quantity
     * @return string
     */
    private function getQuery(string $maskedQuoteId, int $itemId, float $quantity): string
    {
        return <<<QUERY
mutation {
  updateCartItems(input: {
    cart_id: "{$maskedQuoteId}"
    cart_items:[
      {
        cart_item_id: {$itemId}
        quantity: {$quantity}
      }
    ]
  }) {
    cart {
      items {
        id
        quantity
      }
    }
  }
}
QUERY;
    }

    /**
     * @param string $username
     * @param string $password
     * @return array
     */
    private function getHeaderMap(string $username = 'customer@example.com', string $password = 'password'): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($username, $password);
        $headerMap = ['Authorization' => 'Bearer ' . $customerToken];
        return $headerMap;
    }
}
