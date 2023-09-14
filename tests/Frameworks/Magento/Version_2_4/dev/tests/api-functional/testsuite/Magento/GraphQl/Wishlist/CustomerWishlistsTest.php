<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Wishlist;

use Exception;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\ResourceModel\Wishlist\CollectionFactory;
use Magento\Wishlist\Model\Wishlist;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Registry;

/**
 * Test coverage for customer wishlists
 */
class CustomerWishlistsTest extends GraphQlAbstract
{
    /**
     * @var CustomerTokenServiceInterface
     */
    private $customerTokenService;

    /**
     * @var CollectionFactory
     */
    private $wishlistCollectionFactory;

    /**
     * Set Up
     */
    protected function setUp(): void
    {
        $this->customerTokenService = Bootstrap::getObjectManager()->get(CustomerTokenServiceInterface::class);
        $this->wishlistCollectionFactory = Bootstrap::getObjectManager()->get(CollectionFactory::class);
    }

    /**
     * Test fetching customer wishlist
     *
     * @magentoConfigFixture default_store wishlist/general/active 1
     * @magentoApiDataFixture Magento/Wishlist/_files/wishlist.php
     */
    public function testCustomerWishlist(): void
    {
        $customerId = 1;
        /** @var Wishlist $wishlist */
        $collection = $this->wishlistCollectionFactory->create()->filterByCustomerId($customerId);
        /** @var Item $wishlistItem */
        $wishlistItem = $collection->getFirstItem();
        $response = $this->graphQlQuery(
            $this->getQuery(),
            [],
            '',
            $this->getCustomerAuthHeaders('customer@example.com', 'password')
        );
        $this->assertArrayHasKey('wishlists', $response['customer']);
        $wishlist = $response['customer']['wishlists'][0];
        $this->assertEquals($wishlistItem->getItemsCount(), $wishlist['items_count']);
        $this->assertEquals($wishlistItem->getSharingCode(), $wishlist['sharing_code']);
        $this->assertEquals($wishlistItem->getUpdatedAt(), $wishlist['updated_at']);
        $wishlistItemResponse = $wishlist['items_v2']['items'][0];
        $this->assertEquals('simple', $wishlistItemResponse['product']['sku']);
    }

    /**
     * @magentoConfigFixture default_store wishlist/general/active 1
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple_duplicated.php
     * @throws Exception
     */
    public function testWishlistCreationScenario(): void
    {
        try {
            $customerEmail = 'customer2@wishlist.com';
            $this->graphQlMutation(
                $this->getCreateCustomerQuery($customerEmail),
                [],
                ''
            );
            $response = $this->graphQlQuery(
                $this->getQuery(),
                [],
                '',
                $this->getCustomerAuthHeaders($customerEmail, '123123^q')
            );
            $this->assertArrayHasKey('wishlists', $response['customer']);
            $wishlists = $response['customer']['wishlists'];
            $this->assertNotEmpty($wishlists);
            $wishlist = $wishlists[0];
            $this->assertEquals(0, $wishlist['items_count']);
            $sku = 'simple-1';
            $qty = 1;
            $addProductToWishlistQuery =
                <<<QUERY
mutation{
   addProductsToWishlist(
     wishlistId:{$wishlist['id']}
     wishlistItems:[
      {
        sku:"{$sku}"
        quantity:{$qty}
      }
    ])
  {
     wishlist{
     id
     items_count
     items{product{name sku} description qty}
    }
    user_errors{code message}
  }
}

QUERY;
            $addToWishlistResponse = $this->graphQlMutation(
                $addProductToWishlistQuery,
                [],
                '',
                $this->getCustomerAuthHeaders($customerEmail, '123123^q')
            );
            $this->assertArrayHasKey('user_errors', $addToWishlistResponse['addProductsToWishlist']);
            $this->assertCount(0, $addToWishlistResponse['addProductsToWishlist']['user_errors']);
        } finally {
            /** @var Registry $registry */
            $registry = Bootstrap::getObjectManager()
                ->get(Registry::class);
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);

            $objectManager = Bootstrap::getObjectManager();
            $customerRepository = $objectManager->create(CustomerRepositoryInterface::class);
            $customer = $customerRepository->get($customerEmail);
            $customerRepository->delete($customer);

            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', false);
        }
    }

    /**
     * Testing fetching the wishlist when wishlist is disabled
     *
     * @magentoConfigFixture default_store wishlist/general/active 0
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     */
    public function testCustomerCannotGetWishlistWhenDisabled(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The wishlist configuration is currently disabled.');
        $this->graphQlQuery(
            $this->getQuery(),
            [],
            '',
            $this->getCustomerAuthHeaders('customer@example.com', 'password')
        );
    }

    /**
     * Test wishlist fetching for a guest customer
     *
     * @magentoConfigFixture default_store wishlist/general/active 1
     */
    public function testGuestCannotGetWishlist(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The current customer isn\'t authorized.');
        $this->graphQlQuery($this->getQuery());
    }

    /**
     * Returns GraphQl query string
     *
     * @return string
     */
    private function getQuery(): string
    {
        return <<<QUERY
query {
  customer {
    wishlists {
      id
      items_count
      sharing_code
      updated_at
      items_v2 {
        items {
        product {name sku}
        }
      }
    }
  }
}
QUERY;
    }

    private function getCreateCustomerQuery($customerEmail): string
    {
        return <<<QUERY
mutation {
  createCustomer(input: {
    firstname: "test"
    lastname: "test"
    email: "$customerEmail"
    password: "123123^q"
  })
   {
  customer {
    email
  }
}
}
QUERY;
    }

    /**
     * Getting customer auth headers
     *
     * @param string $email
     * @param string $password
     *
     * @return array
     *
     * @throws AuthenticationException
     */
    private function getCustomerAuthHeaders(string $email, string $password): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($email, $password);

        return ['Authorization' => 'Bearer ' . $customerToken];
    }
}
