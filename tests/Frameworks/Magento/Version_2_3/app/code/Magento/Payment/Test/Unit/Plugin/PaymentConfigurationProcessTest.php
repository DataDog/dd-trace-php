<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Test\Unit\Plugin;

/**
 * Class PaymentConfigurationProcessTest.
 */
class PaymentConfigurationProcessTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManager;

    /**
     * @var \Magento\Store\Api\Data\StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $store;

    /**
     * @var \Magento\Payment\Api\PaymentMethodListInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $paymentMethodList;

    /**
     * @var \Magento\Checkout\Block\Checkout\LayoutProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $layoutProcessor;

    /**
     * @var \Magento\Payment\Plugin\PaymentConfigurationProcess
     */
    private $plugin;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->storeManager = $this
            ->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStore'])
            ->getMockForAbstractClass();
        $this->store = $this
            ->getMockBuilder(\Magento\Store\Api\Data\StoreInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getId'])
            ->getMockForAbstractClass();
        $this->paymentMethodList = $this
            ->getMockBuilder(\Magento\Payment\Api\PaymentMethodListInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getActiveList'])
            ->getMockForAbstractClass();
        $this->layoutProcessor =  $this
            ->getMockBuilder(\Magento\Checkout\Block\Checkout\LayoutProcessor::class)
            ->disableOriginalConstructor()
            ->setMethods(['process'])
            ->getMockForAbstractClass();

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->plugin = $objectManagerHelper->getObject(
            \Magento\Payment\Plugin\PaymentConfigurationProcess::class,
            [
                'paymentMethodList' => $this->paymentMethodList,
                'storeManager' => $this->storeManager
            ]
        );
    }

    /**
     * @param array $jsLayout
     * @param array $activePaymentList
     * @param array $expectedResult
     * @dataProvider beforeProcessDataProvider
     */
    public function testBeforeProcess($jsLayout, $activePaymentList, $expectedResult)
    {
        $this->store->expects($this->once())->method('getId')->willReturn(1);
        $this->storeManager->expects($this->once())->method('getStore')->willReturn($this->store);
        $this->paymentMethodList->expects($this->once())
            ->method('getActiveList')
            ->with(1)
            ->willReturn($activePaymentList);

        $result = $this->plugin->beforeProcess($this->layoutProcessor, $jsLayout);
        $this->assertEquals($result[0], $expectedResult);
    }

    /**
     * Data provider for BeforeProcess.
     *
     * @return array
     */
    public function beforeProcessDataProvider()
    {
        $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
        ['children']['payment']['children']['renders']['children'] = [
            'braintree' => [
                'methods' => [
                    'braintree_paypal' => [],
                    'braintree' => []
                ]
            ],
            'paypal-payments' => [
                'methods' => [
                    'payflowpro' => [],
                    'payflow_link' => []
                ]
            ]
        ];
        $result1['components']['checkout']['children']['steps']['children']['billing-step']
        ['children']['payment']['children']['renders']['children'] = [];
        $result2['components']['checkout']['children']['steps']['children']['billing-step']
        ['children']['payment']['children']['renders']['children'] = [
            'braintree' => [
                'methods' => [
                    'braintree' => [],
                    'braintree_paypal' => []
                ]
            ]
        ];

        $braintreePaymentMethod = $this
            ->getMockBuilder(\Magento\Payment\Api\Data\PaymentMethodInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCode'])
            ->getMockForAbstractClass();
        $braintreePaypalPaymentMethod = $this
            ->getMockBuilder(\Magento\Payment\Api\Data\PaymentMethodInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCode'])
            ->getMockForAbstractClass();

        $braintreePaymentMethod->expects($this->any())->method('getCode')->willReturn('braintree');
        $braintreePaypalPaymentMethod->expects($this->any())->method('getCode')->willReturn('braintree_paypal');

        return [
            [$jsLayout, [], $result1],
            [$jsLayout, [$braintreePaymentMethod, $braintreePaypalPaymentMethod], $result2]
        ];
    }
}
