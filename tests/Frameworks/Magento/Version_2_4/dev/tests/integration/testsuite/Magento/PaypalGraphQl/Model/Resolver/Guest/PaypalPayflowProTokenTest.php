<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\PaypalGraphQl\Model\Resolver\Guest;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\PaypalGraphQl\PaypalPayflowProAbstractTest;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteId;
use Magento\Framework\DataObject;

/**
 * Test PaypalPayflowProTokenTest graphql endpoint for guest
 *
 * @magentoAppArea graphql
 */
class PaypalPayflowProTokenTest extends PaypalPayflowProAbstractTest
{
    /**
     * @var SerializerInterface
     */
    private $json;

    /**
     * @var QuoteIdToMaskedQuoteId
     */
    private $quoteIdToMaskedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->json = $this->objectManager->get(SerializerInterface::class);
        $this->quoteIdToMaskedId = $this->objectManager->get(QuoteIdToMaskedQuoteId::class);
    }

    /**
     * Test create paypal token for guest
     *
     * @return void
     * @magentoDataFixture Magento/Sales/_files/default_rollback.php
     * @magentoDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/guest/set_guest_email.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_new_billing_address.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testResolve(): void
    {
        $this->enablePaymentMethod('payflowpro');
        $reservedQuoteId = 'test_quote';
        $cart = $this->getQuoteByReservedOrderId($reservedQuoteId);

        $cartId = $this->quoteIdToMaskedId->execute((int)$cart->getId());
        $query = $this->getCreatePayflowTokenMutation($cartId);

        $paypalResponse = new DataObject(
            [
                'result' => '0',
                'securetoken' => 'mysecuretoken',
                'securetokenid' => 'mysecuretokenid',
                'respmsg' => 'Approved',
                'result_code' => '0',
            ]
        );

        $this->gatewayMock
            ->method('postRequest')
            ->willReturn($paypalResponse);

        $response = $this->graphQlRequest->send($query);
        $responseData = $this->json->unserialize($response->getContent());
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('createPayflowProToken', $responseData['data']);
        $createTokenData = $responseData['data']['createPayflowProToken'];

        $this->assertArrayNotHasKey('errors', $responseData);
        $this->assertEquals($paypalResponse->getData('securetoken'), $createTokenData['secure_token']);
        $this->assertEquals($paypalResponse->getData('securetokenid'), $createTokenData['secure_token_id']);
        $this->assertEquals($paypalResponse->getData('result'), $createTokenData['result']);
        $this->assertEquals($paypalResponse->getData('result_code'), $createTokenData['result_code']);
        $this->assertEquals($paypalResponse->getData('respmsg'), $createTokenData['response_message']);
    }

    /**
     * Test redirect Urls are validated
     *
     * @return void
     * @magentoDataFixture Magento/GraphQl/Quote/_files/guest/create_empty_cart.php
     */
    public function testResolveWithInvalidRedirectUrl(): void
    {
        $this->enablePaymentMethod('payflowpro');
        $reservedQuoteId = 'test_quote';
        $cart = $this->getQuoteByReservedOrderId($reservedQuoteId);

        $cartId = $this->quoteIdToMaskedId->execute((int)$cart->getId());
        $query = <<<QUERY
mutation {
  createPayflowProToken(
    input: {
      cart_id:"{$cartId}",
      urls: {
        cancel_url: "paypal/transparent/cancel/"
        error_url: "http://domain/paypal/transparent/cancel"
        return_url: "paypal/transparent/response/"
      }
    }
  ) {
    response_message
    result
    result_code
    secure_token
    secure_token_id
  }
}
QUERY;

        $expectedExceptionMessage = "Invalid Url.";

        $response = $this->graphQlRequest->send($query);
        $responseData = $this->json->unserialize($response->getContent());

        $this->assertArrayHasKey('errors', $responseData);
        $actualError = $responseData['errors'][0];
        $this->assertEquals($expectedExceptionMessage, $actualError['message']);
        $this->assertEquals(GraphQlInputException::EXCEPTION_CATEGORY, $actualError['extensions']['category']);
    }
}
