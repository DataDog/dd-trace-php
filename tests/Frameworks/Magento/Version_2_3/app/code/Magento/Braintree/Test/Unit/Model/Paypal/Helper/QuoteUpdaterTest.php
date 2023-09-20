<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Braintree\Test\Unit\Model\Paypal\Helper;

use Magento\Braintree\Gateway\Config\PayPal\Config;
use Magento\Braintree\Model\Paypal\Helper\QuoteUpdater;
use Magento\Braintree\Model\Ui\PayPal\ConfigProvider;
use Magento\Braintree\Observer\DataAssignObserver;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartExtensionInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

/**
 * Class QuoteUpdaterTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class QuoteUpdaterTest extends \PHPUnit\Framework\TestCase
{
    const TEST_NONCE = '3ede7045-2aea-463e-9754-cd658ffeeb48';

    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var CartRepositoryInterface|MockObject
     */
    private $quoteRepository;

    /**
     * @var Address|MockObject
     */
    private $billingAddress;

    /**
     * @var Address|MockObject
     */
    private $shippingAddress;

    /**
     * @var QuoteUpdater
     */
    private $quoteUpdater;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->quoteRepository = $this->getMockBuilder(CartRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->billingAddress = $this->getMockBuilder(Address::class)
            ->setMethods(
                [
                    'setLastname',
                    'setFirstname',
                    'setEmail',
                    'setCollectShippingRates',
                    'setStreet',
                    'setCity',
                    'setRegionCode',
                    'setCountryId',
                    'setPostcode',
                    'setShouldIgnoreValidation',
                    'getEmail'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();
        $this->shippingAddress = $this->getMockBuilder(Address::class)
            ->setMethods(
                [
                    'setLastname',
                    'setFirstname',
                    'setEmail',
                    'setCollectShippingRates',
                    'setStreet',
                    'setCity',
                    'setRegionCode',
                    'setCountryId',
                    'setPostcode',
                    'setShouldIgnoreValidation'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();

        $this->quoteUpdater = new QuoteUpdater(
            $this->config,
            $this->quoteRepository
        );
    }

    /**
     * Checks if quote details can be update by the response from Braintree.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testExecute(): void
    {
        $details = $this->getDetails();
        $quote = $this->getQuoteMock();
        $payment = $this->getPaymentMock();

        $quote->method('getPayment')
            ->willReturn($payment);

        $payment->method('setMethod')
            ->with(ConfigProvider::PAYPAL_CODE);
        $payment->method('setAdditionalInformation')
            ->with(DataAssignObserver::PAYMENT_METHOD_NONCE, self::TEST_NONCE);

        $this->updateQuoteStep($quote, $details);

        $this->quoteUpdater->execute(self::TEST_NONCE, $details, $quote);
    }

    /**
     * Disables quote's addresses validation.
     *
     * @return void
     */
    private function disabledQuoteAddressValidationStep(): void
    {
        $this->billingAddress->method('setShouldIgnoreValidation')
            ->with(true);
        $this->shippingAddress->method('setShouldIgnoreValidation')
            ->with(true);
        $this->billingAddress->method('getEmail')
            ->willReturn('bt_buyer_us@paypal.com');
    }

    /**
     * Gets quote's details.
     *
     * @return array
     */
    private function getDetails(): array
    {
        return [
            'email' => 'bt_buyer_us@paypal.com',
            'payerId' => 'FAKE_PAYER_ID',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '312-123-4567',
            'countryCode' => 'US',
            'shippingAddress' => [
                'line1' => '123 Division Street',
                'line2' => 'Apt. #1',
                'city' => 'Chicago',
                'state' => 'IL',
                'postalCode' => '60618',
                'countryCode' => 'US',
                'recipientName' => 'Jane Smith',
            ],
            'billingAddress' => [
                'line1' => '123 Billing Street',
                'line2' => 'Apt. #1',
                'city' => 'Chicago',
                'state' => 'IL',
                'postalCode' => '60618',
                'countryCode' => 'US',
            ],
        ];
    }

    /**
     * Updates shipping address details.
     *
     * @param array $details
     */
    private function updateShippingAddressStep(array $details): void
    {
        $this->shippingAddress->method('setLastname')
            ->with('Smith');
        $this->shippingAddress->method('setFirstname')
            ->with('Jane');
        $this->shippingAddress->method('setEmail')
            ->with($details['email']);
        $this->shippingAddress->method('setCollectShippingRates')
            ->with(true);

        $this->updateAddressDataStep($this->shippingAddress, $details['shippingAddress']);
    }

    /**
     * Updates address details.
     *
     * @param MockObject $address
     * @param array $addressData
     */
    private function updateAddressDataStep(MockObject $address, array $addressData): void
    {
        $address->method('setStreet')
            ->with([$addressData['line1'], $addressData['line2']]);
        $address->method('setCity')
            ->with($addressData['city']);
        $address->method('setRegionCode')
            ->with($addressData['state']);
        $address->method('setCountryId')
            ->with($addressData['countryCode']);
        $address->method('setPostcode')
            ->with($addressData['postalCode']);
    }

    /**
     * Updates quote's address details.
     *
     * @param MockObject $quoteMock
     * @param array $details
     */
    private function updateQuoteAddressStep(MockObject $quoteMock, array $details): void
    {
        $quoteMock->expects(self::exactly(2))
            ->method('getIsVirtual')
            ->willReturn(false);

        $this->updateShippingAddressStep($details);
        $this->updateBillingAddressStep($details);
    }

    /**
     * Updates billing address details.
     *
     * @param array $details
     */
    private function updateBillingAddressStep(array $details): void
    {
        $this->config->method('isRequiredBillingAddress')
            ->willReturn(true);

        $this->updateAddressDataStep($this->billingAddress, $details['billingAddress']);

        $this->billingAddress->method('setLastname')
            ->with($details['lastName']);
        $this->billingAddress->method('setFirstname')
            ->with($details['firstName']);
        $this->billingAddress->method('setEmail')
            ->with($details['email']);
    }

    /**
     * Updates quote details.
     *
     * @param MockObject $quote
     * @param array $details
     */
    private function updateQuoteStep(MockObject $quote, array $details): void
    {
        $quote->method('setMayEditShippingAddress')
            ->with(false);
        $quote->method('setMayEditShippingMethod')
            ->with(true);

        $quote->method('getShippingAddress')
            ->willReturn($this->shippingAddress);
        $quote->expects(self::exactly(2))
            ->method('getBillingAddress')
            ->willReturn($this->billingAddress);

        $this->updateQuoteAddressStep($quote, $details);
        $this->disabledQuoteAddressValidationStep();

        $quote->method('collectTotals');

        $this->quoteRepository->method('save')
            ->with($quote);
    }

    /**
     * Creates a mock for Quote object.
     *
     * @return Quote|MockObject
     */
    private function getQuoteMock(): MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->setMethods(
                [
                    'getIsVirtual',
                    'getPayment',
                    'setMayEditShippingAddress',
                    'setMayEditShippingMethod',
                    'collectTotals',
                    'getShippingAddress',
                    'getBillingAddress',
                    'getExtensionAttributes'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();

        $cartExtension = $this->getMockBuilder(CartExtensionInterface::class)
            ->setMethods(['setShippingAssignments'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $quote->method('getExtensionAttributes')
            ->willReturn($cartExtension);

        return $quote;
    }

    /**
     * Creates a mock for Payment object.
     *
     * @return Payment|MockObject
     */
    private function getPaymentMock(): MockObject
    {
        return $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
