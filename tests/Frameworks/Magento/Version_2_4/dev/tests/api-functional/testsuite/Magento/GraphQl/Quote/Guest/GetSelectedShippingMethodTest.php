<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Quote\Guest;

use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Test for get selected shipping method
 */
class GetSelectedShippingMethodTest extends GraphQlAbstract
{
    /**
     * @var GetMaskedQuoteIdByReservedOrderId
     */
    private $getMaskedQuoteIdByReservedOrderId;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->getMaskedQuoteIdByReservedOrderId = $objectManager->get(GetMaskedQuoteIdByReservedOrderId::class);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testGetSelectedShippingMethod()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');

        $query = $this->getQuery($maskedQuoteId);
        $response = $this->graphQlQuery($query);

        self::assertArrayHasKey('cart', $response);
        self::assertArrayHasKey('shipping_addresses', $response['cart']);
        self::assertCount(1, $response['cart']['shipping_addresses']);

        $shippingAddress = current($response['cart']['shipping_addresses']);
        self::assertArrayHasKey('selected_shipping_method', $shippingAddress);

        self::assertArrayHasKey('carrier_code', $shippingAddress['selected_shipping_method']);
        self::assertEquals('flatrate', $shippingAddress['selected_shipping_method']['carrier_code']);

        self::assertArrayHasKey('method_code', $shippingAddress['selected_shipping_method']);
        self::assertEquals('flatrate', $shippingAddress['selected_shipping_method']['method_code']);

        self::assertArrayHasKey('carrier_title', $shippingAddress['selected_shipping_method']);
        self::assertEquals('Flat Rate', $shippingAddress['selected_shipping_method']['carrier_title']);

        self::assertArrayHasKey('method_title', $shippingAddress['selected_shipping_method']);
        self::assertEquals('Fixed', $shippingAddress['selected_shipping_method']['method_title']);

        self::assertArrayHasKey('amount', $shippingAddress['selected_shipping_method']);
        $amount = $shippingAddress['selected_shipping_method']['amount'];

        self::assertArrayHasKey('value', $amount);
        self::assertEquals(10, $amount['value']);
        self::assertArrayHasKey('currency', $amount);
        self::assertEquals('USD', $amount['currency']);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     */
    public function testGetSelectedShippingMethodBeforeSet()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');

        $query = $this->getQuery($maskedQuoteId);
        $response = $this->graphQlQuery($query);

        self::assertArrayHasKey('cart', $response);
        self::assertArrayHasKey('shipping_addresses', $response['cart']);
        self::assertCount(1, $response['cart']['shipping_addresses']);

        $shippingAddress = current($response['cart']['shipping_addresses']);
        self::assertArrayHasKey('selected_shipping_method', $shippingAddress);
        self::assertNull($shippingAddress['selected_shipping_method']);
    }

    /**
     * _security
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testGetSelectedShippingMethodFromCustomerCart()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $query = $this->getQuery($maskedQuoteId);

        $this->expectExceptionMessage(
            "The current user cannot perform operations on cart \"$maskedQuoteId\""
        );
        $this->graphQlQuery($query);
    }

    /**
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoApiDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     */
    public function testGetGetSelectedShippingMethodIfShippingMethodIsNotSet()
    {
        $maskedQuoteId = $this->getMaskedQuoteIdByReservedOrderId->execute('test_quote');
        $query = $this->getQuery($maskedQuoteId);

        $response = $this->graphQlQuery($query);

        self::assertArrayHasKey('cart', $response);
        self::assertArrayHasKey('shipping_addresses', $response['cart']);
        self::assertCount(1, $response['cart']['shipping_addresses']);

        $shippingAddress = current($response['cart']['shipping_addresses']);
        self::assertArrayHasKey('selected_shipping_method', $shippingAddress);
        self::assertNull($shippingAddress['selected_shipping_method']);
    }

    /**
     */
    public function testGetSelectedShippingMethodOfNonExistentCart()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not find a cart with ID "non_existent_masked_id"');

        $maskedQuoteId = 'non_existent_masked_id';
        $query = $this->getQuery($maskedQuoteId);
        $this->graphQlQuery($query);
    }

    /**
     * @param string $maskedQuoteId
     * @return string
     */
    private function getQuery(string $maskedQuoteId): string
    {
        return <<<QUERY
{
  cart(cart_id: "$maskedQuoteId") {
    shipping_addresses {
      available_shipping_methods {
        carrier_code
        method_code
      }
      selected_shipping_method {
        carrier_code
        method_code
        carrier_title
        method_title
        amount {
            value
            currency
        }
      }
    }
  }
}
QUERY;
    }
}
