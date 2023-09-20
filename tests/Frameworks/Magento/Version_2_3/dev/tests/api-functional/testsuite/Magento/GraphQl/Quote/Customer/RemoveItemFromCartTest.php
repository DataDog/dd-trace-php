<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Quote\Customer;

use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\GraphQl\Quote\GetQuoteItemIdByReservedQuoteIdAndSku;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 *  Test for removeItemFromCartTest mutation
 */
class RemoveItemFromCartTest extends GraphQlAbstract
{
    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @var GetMaskedQuoteIdByReservedOrderId
     */
    private $getMaskedQuoteIdByReservedOrderId;

    /**
     * @var GetQuoteItemIdByReservedQuoteIdAndSku
     */
    private $getQuoteItemIdByReservedQuoteIdAndSku;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
        $this->getMaskedQuoteIdByReservedOrderId = $objectManager->get(GetMaskedQuoteIdByReservedOrderId::class);
        $this->getQuoteItemIdByReservedQuoteIdAndSku = $objectManager->get(
            GetQuoteItemIdByReservedQuoteIdAndSku::class
        );
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     */
    public function testRemoveItemFromCart()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $itemId = $this->getQuoteItemIdByReservedQuoteIdAndSku->execute('test_quote', 'simple_product');

        $query = $this->getQuery($maskedQuoteId, $itemId);
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());

        $this->assertArrayHasKey('removeItemFromCart', $response);
        $this->assertArrayHasKey('cart', $response['removeItemFromCart']);
        $this->assertCount(0, $response['removeItemFromCart']['cart']['items']);
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     */
    public function testRemoveItemFromNonExistentCart()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not find a cart with ID "non_existent_masked_id"');

        $query = $this->getQuery('non_existent_masked_id', 1);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     */
    public function testRemoveNonExistentItem()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $notExistentItemId = 999;

        $this->expectExceptionMessage("Cart doesn't contain the {$notExistentItemId} item.");

        $query = $this->getQuery($maskedQuoteId, $notExistentItemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * _security
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/Checkout/_files/quote_with_virtual_product_and_address.php
     */
    public function testRemoveItemIfItemIsNotBelongToCart()
    {
        $firstQuoteMaskedId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $secondQuoteItemId = $this->getQuoteItemIdByReservedQuoteIdAndSku->execute(
            'test_order_with_virtual_product',
            'virtual-product'
        );

        $this->expectExceptionMessage("Cart doesn't contain the {$secondQuoteItemId} item.");

        $query = $this->getQuery($firstQuoteMaskedId, $secondQuoteItemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * _security
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     */
    public function testRemoveItemFromGuestCart()
    {
        $guestQuoteMaskedId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $guestQuoteItemId = $this->getQuoteItemIdByReservedQuoteIdAndSku->execute('test_quote', 'simple_product');

        $this->expectExceptionMessage(
            "The current user cannot perform operations on cart \"$guestQuoteMaskedId\""
        );

        $query = $this->getQuery($guestQuoteMaskedId, $guestQuoteItemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * _security
     * @magentoApiDataFixture Magento/Customer/_files/three_customers.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     */
    public function testRemoveItemFromAnotherCustomerCart()
    {
        $anotherCustomerQuoteMaskedId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $anotherCustomerQuoteItemId = $this->getQuoteItemIdByReservedQuoteIdAndSku->execute(
            'test_quote',
            'simple_product'
        );

        $this->expectExceptionMessage(
            "The current user cannot perform operations on cart \"$anotherCustomerQuoteMaskedId\""
        );

        $query = $this->getQuery($anotherCustomerQuoteMaskedId, $anotherCustomerQuoteItemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap('customer2@search.example.com'));
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     *
     */
    public function testWithoutRequiredCartIdParameter()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Required parameter "cart_id" is missing');

        $maskedQuoteId = '';
        $itemId = $this->getQuoteItemIdByReservedQuoteIdAndSku->execute('test_quote', 'simple_product');

        $query = $this->getQuery($maskedQuoteId, $itemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     *
     */
    public function testWithoutRequiredCartItemIdParameter()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Required parameter "cart_item_id" is missing.');

        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $itemId = 0;

        $query = $this->getQuery($maskedQuoteId, $itemId);
        $this->graphQlMutation($query, [], '', $this->getHeaderMap());
    }

    /**
     * @param string $maskedQuoteId
     * @param int $itemId
     * @return string
     */
    private function getQuery(string $maskedQuoteId, int $itemId): string
    {
        return <<<QUERY
mutation {
  removeItemFromCart(
    input: {
      cart_id: "{$maskedQuoteId}"
      cart_item_id: {$itemId}
    }
  ) {
    cart {
      items {
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
