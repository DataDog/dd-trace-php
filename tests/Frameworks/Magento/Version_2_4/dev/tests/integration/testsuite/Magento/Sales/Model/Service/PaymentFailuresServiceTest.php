<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Service;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\PaymentFailuresInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests \Magento\Sales\Api\PaymentFailuresInterface.
 */
class PaymentFailuresServiceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var PaymentFailuresInterface
     */
    private $paymentFailures;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var CartRepositoryInterface|MockObject
     */
    private $cartRepositoryMock;

    /**
     * @var TimezoneInterface|MockObject
     */
    private $localeDateMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->quote = Bootstrap::getObjectManager()->create(Quote::class);
        $this->cartRepositoryMock = $this->getMockBuilder(CartRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['get'])
            ->getMockForAbstractClass();
        $this->localeDateMock = $this->getMockBuilder(TimezoneInterface::class)
            ->setMethods(['formatDateTime'])
            ->getMockForAbstractClass();

        $this->paymentFailures = Bootstrap::getObjectManager()->create(
            PaymentFailuresInterface::class,
            [
                'cartRepository' => $this->cartRepositoryMock,
                'localeDate' => $this->localeDateMock,
            ]
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote_with_two_products_and_customer.php
     * @magentoConfigFixture cataloginventory/options/enable_inventory_check 1
     * @magentoConfigFixture current_store payment/payflowpro/title Some Title Of The Method
     * @magentoConfigFixture current_store carriers/freeshipping/title Some Shipping Method
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testHandlerWithCustomer(): void
    {
        $errorMessage = __('Transaction declined.');
        $checkoutType = 'custom_checkout';

        $this->quote->load('test01', 'reserved_order_id');
        $this->cartRepositoryMock->method('get')
            ->with($this->quote->getId())
            ->willReturn($this->quote);

        $dateAndTime = 'Nov 22, 2019, 1:00:00 AM';
        $this->localeDateMock->expects($this->atLeastOnce())->method('formatDateTime')->willReturn($dateAndTime);
        $this->paymentFailures->handle((int)$this->quote->getId(), $errorMessage->render());

        $paymentReflection = new \ReflectionClass($this->paymentFailures);
        $templateVarsMethod = $paymentReflection->getMethod('getTemplateVars');
        $templateVarsMethod->setAccessible(true);

        $templateVars = $templateVarsMethod->invoke($this->paymentFailures, $this->quote, $errorMessage, $checkoutType);
        $expectedVars = [
            'reason' => $errorMessage->render(),
            'checkoutType' => $checkoutType,
            'dateAndTime' => $dateAndTime,
            'customer' => 'John Smith',
            'customerEmail' => 'aaa@aaa.com',
            'paymentMethod' => 'Some Title Of The Method',
            'shippingMethod' => 'Some Shipping Method',
            'items' => 'Simple Product  x 2  USD 10<br />Custom Design Simple Product  x 1  USD 10',
            'total' => 'USD 30.0000',
            'billingAddress' => $this->quote->getBillingAddress(),
            'shippingAddress' => $this->quote->getShippingAddress(),
            'billingAddressHtml' => $this->quote->getBillingAddress()->format('html'),
            'shippingAddressHtml' => $this->quote->getShippingAddress()->format('html'),
        ];

        $this->assertEquals($expectedVars, $templateVars);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote_with_two_products_and_customer.php
     * @magentoConfigFixture cataloginventory/options/enable_inventory_check 0
     * @magentoConfigFixture current_store payment/payflowpro/title Some Title Of The Method
     * @magentoConfigFixture current_store carriers/freeshipping/title Some Shipping Method
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testHandlerWithCustomerWithInventoryCheckDisabled(): void
    {
        $errorMessage = __('Transaction declined.');
        $checkoutType = 'custom_checkout';

        $this->quote->load('test01', 'reserved_order_id');
        $this->cartRepositoryMock->method('get')
            ->with($this->quote->getId())
            ->willReturn($this->quote);

        $dateAndTime = 'Nov 22, 2019, 1:00:00 AM';
        $this->localeDateMock->expects($this->atLeastOnce())->method('formatDateTime')->willReturn($dateAndTime);
        $this->paymentFailures->handle((int)$this->quote->getId(), $errorMessage->render());

        $paymentReflection = new \ReflectionClass($this->paymentFailures);
        $templateVarsMethod = $paymentReflection->getMethod('getTemplateVars');
        $templateVarsMethod->setAccessible(true);

        $templateVars = $templateVarsMethod->invoke($this->paymentFailures, $this->quote, $errorMessage, $checkoutType);
        $expectedVars = [
            'reason' => $errorMessage->render(),
            'checkoutType' => $checkoutType,
            'dateAndTime' => $dateAndTime,
            'customer' => 'John Smith',
            'customerEmail' => 'aaa@aaa.com',
            'paymentMethod' => 'Some Title Of The Method',
            'shippingMethod' => 'Some Shipping Method',
            'items' => 'Simple Product  x 2.0000  USD 10<br />Custom Design Simple Product  x 1.0000  USD 10',
            'total' => 'USD 30.0000',
            'billingAddress' => $this->quote->getBillingAddress(),
            'shippingAddress' => $this->quote->getShippingAddress(),
            'billingAddressHtml' => $this->quote->getBillingAddress()->format('html'),
            'shippingAddressHtml' => $this->quote->getShippingAddress()->format('html'),
        ];

        $this->assertEquals($expectedVars, $templateVars);
    }
}
