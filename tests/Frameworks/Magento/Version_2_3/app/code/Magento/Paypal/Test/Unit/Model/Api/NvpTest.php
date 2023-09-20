<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Paypal\Test\Unit\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Paypal\Model\Info;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class NvpTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Paypal\Model\Api\Nvp */
    protected $model;

    /** @var \Magento\Customer\Helper\Address|\PHPUnit\Framework\MockObject\MockObject */
    protected $customerAddressHelper;

    /** @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $logger;

    /** @var \Magento\Framework\Locale\ResolverInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $resolver;

    /** @var \Magento\Directory\Model\RegionFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $regionFactory;

    /** @var \Magento\Directory\Model\CountryFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $countryFactory;

    /** @var \Magento\Paypal\Model\Api\ProcessableException|\PHPUnit\Framework\MockObject\MockObject */
    protected $processableException;

    /** @var LocalizedException|\PHPUnit\Framework\MockObject\MockObject */
    protected $exception;

    /** @var \Magento\Framework\HTTP\Adapter\Curl|\PHPUnit\Framework\MockObject\MockObject */
    protected $curl;

    /** @var \Magento\Paypal\Model\Config|\PHPUnit\Framework\MockObject\MockObject */
    protected $config;

    /** @var \Magento\Payment\Model\Method\Logger|\PHPUnit\Framework\MockObject\MockObject */
    protected $customLoggerMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->customerAddressHelper = $this->createMock(\Magento\Customer\Helper\Address::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->customLoggerMock = $this->getMockBuilder(\Magento\Payment\Model\Method\Logger::class)
            ->setConstructorArgs([$this->getMockForAbstractClass(\Psr\Log\LoggerInterface::class)])
            ->setMethods(['debug'])
            ->getMock();
        $this->resolver = $this->createMock(\Magento\Framework\Locale\ResolverInterface::class);
        $this->regionFactory = $this->createMock(\Magento\Directory\Model\RegionFactory::class);
        $this->countryFactory = $this->createMock(\Magento\Directory\Model\CountryFactory::class);
        $processableExceptionFactory = $this->createPartialMock(
            \Magento\Paypal\Model\Api\ProcessableExceptionFactory::class,
            ['create']
        );
        $processableExceptionFactory->expects($this->any())
            ->method('create')
            ->willReturnCallback(
                
                    function ($arguments) {
                        $this->processableException = $this->getMockBuilder(
                            \Magento\Paypal\Model\Api\ProcessableException::class
                        )->setConstructorArgs([$arguments['phrase'], null, $arguments['code']])->getMock();
                        return $this->processableException;
                    }
                
            );
        $exceptionFactory = $this->createPartialMock(
            \Magento\Framework\Exception\LocalizedExceptionFactory::class,
            ['create']
        );
        $exceptionFactory->expects($this->any())
            ->method('create')
            ->willReturnCallback(
                
                    function ($arguments) {
                        $this->exception = $this->getMockBuilder(LocalizedException::class)
                            ->setConstructorArgs([$arguments['phrase']])
                            ->getMock();
                        return $this->exception;
                    }
                
            );
        $this->curl = $this->createMock(\Magento\Framework\HTTP\Adapter\Curl::class);
        $curlFactory = $this->createPartialMock(\Magento\Framework\HTTP\Adapter\CurlFactory::class, ['create']);
        $curlFactory->expects($this->any())->method('create')->willReturn($this->curl);
        $this->config = $this->createMock(\Magento\Paypal\Model\Config::class);

        $helper = new ObjectManagerHelper($this);
        $this->model = $helper->getObject(
            \Magento\Paypal\Model\Api\Nvp::class,
            [
                'customerAddress' => $this->customerAddressHelper,
                'logger' => $this->logger,
                'customLogger' => $this->customLoggerMock,
                'localeResolver' => $this->resolver,
                'regionFactory' => $this->regionFactory,
                'countryFactory' => $this->countryFactory,
                'processableExceptionFactory' => $processableExceptionFactory,
                'frameworkExceptionFactory' => $exceptionFactory,
                'curlFactory' => $curlFactory,
            ]
        );
        $this->model->setConfigObject($this->config);
    }

    /**
     * @param \Magento\Paypal\Model\Api\Nvp $nvpObject
     * @param string $property
     * @return mixed
     */
    protected function _invokeNvpProperty(\Magento\Paypal\Model\Api\Nvp $nvpObject, $property)
    {
        $object = new \ReflectionClass($nvpObject);
        $property = $object->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($nvpObject);
    }

    /**
     * @param string $response
     * @param array $processableErrors
     * @param null|string $exception
     * @param string $exceptionMessage
     * @param null|int $exceptionCode
     * @dataProvider callDataProvider
     */
    public function testCall($response, $processableErrors, $exception, $exceptionMessage = '', $exceptionCode = null)
    {
        if (isset($exception)) {
            $this->expectException($exception);
            $this->expectExceptionMessage($exceptionMessage);
            $this->expectExceptionCode($exceptionCode);
        }
        $this->curl->expects($this->once())
            ->method('read')
            ->willReturn($response);
        $this->model->setProcessableErrors($processableErrors);
        $this->customLoggerMock->expects($this->once())
            ->method('debug');
        $this->model->call('some method', ['data' => 'some data']);
    }

    /**
     * @return array
     */
    public function callDataProvider()
    {
        return [
            ['', [], null],
            [
                "\r\n" . 'ACK=Failure&L_ERRORCODE0=10417&L_SHORTMESSAGE0=Message.&L_LONGMESSAGE0=Long%20Message.',
                [],
                LocalizedException::class,
                'PayPal gateway has rejected request. Long Message (#10417: Message).',
                0
            ],
            [
                "\r\n" . 'ACK=Failure&L_ERRORCODE0=10417&L_SHORTMESSAGE0=Message.&L_LONGMESSAGE0=Long%20Message.',
                [10417, 10422],
                \Magento\Paypal\Model\Api\ProcessableException::class,
                'PayPal gateway has rejected request. Long Message (#10417: Message).',
                10417
            ],
            [
                "\r\n" . 'ACK[7]=Failure&L_ERRORCODE0[5]=10417'
                    . '&L_SHORTMESSAGE0[8]=Message.&L_LONGMESSAGE0[15]=Long%20Message.',
                [10417, 10422],
                \Magento\Paypal\Model\Api\ProcessableException::class,
                'PayPal gateway has rejected request. Long Message (#10417: Message).',
                10417
            ],
            [
                "\r\n" . 'ACK[7]=Failure&L_ERRORCODE0[5]=10417&L_SHORTMESSAGE0[8]=Message.',
                [10417, 10422],
                \Magento\Paypal\Model\Api\ProcessableException::class,
                'PayPal gateway has rejected request. #10417: Message.',
                10417
            ],
        ];
    }

    /**
     * Test getting of the ExpressCheckout details
     *
     * @param $input
     * @param $expected
     * @dataProvider callGetExpressCheckoutDetailsDataProvider
     */
    public function testCallGetExpressCheckoutDetails($input, $expected)
    {
        $this->curl->expects($this->once())
            ->method('read')
            ->willReturn($input);
        $this->model->callGetExpressCheckoutDetails();
        $address = $this->model->getExportedShippingAddress();
        $this->assertEquals($expected['firstName'], $address->getData('firstname'));
        $this->assertEquals($expected['lastName'], $address->getData('lastname'));
        $this->assertEquals($expected['street'], $address->getStreet());
        $this->assertEquals($expected['company'], $address->getCompany());
        $this->assertEquals($expected['city'], $address->getCity());
        $this->assertEquals($expected['telephone'], $address->getTelephone());
        $this->assertEquals($expected['region'], $address->getRegion());
    }

    /**
     * Data Provider
     *
     * @return array
     */
    public function callGetExpressCheckoutDetailsDataProvider()
    {
        return [
            [
                "\r\n" . 'ACK=Success&SHIPTONAME=Jane%20Doe'
                . '&SHIPTOSTREET=testStreet'
                . '&SHIPTOSTREET2=testApartment'
                . '&BUSINESS=testCompany'
                . '&SHIPTOCITY=testCity'
                . '&PHONENUM=223322'
                . '&STATE=testSTATE',
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'street' => 'testStreet' . "\n" . 'testApartment',
                    'company' => 'testCompany',
                    'city' => 'testCity',
                    'telephone' => '223322',
                    'region' => 'testSTATE',
                ]
            ]
        ];
    }

    /**
     * Tests that callDoReauthorization method is called without errors and
     * needed data is imported from response.
     */
    public function testCallDoReauthorization()
    {
        $authorizationId = 555;
        $paymentStatus = 'Completed';
        $pendingReason = 'none';
        $protectionEligibility = 'Eligible';
        $protectionEligibilityType = 'ItemNotReceivedEligible';

        $this->curl->expects($this->once())
            ->method('read')
            ->willReturn(
                "\r\n" . 'ACK=Success'
                . '&AUTHORIZATIONID=' . $authorizationId
                . '&PAYMENTSTATUS=' . $paymentStatus
                . '&PENDINGREASON=' . $pendingReason
                . '&PROTECTIONELIGIBILITY=' . $protectionEligibility
                . '&PROTECTIONELIGIBILITYTYPE=' . $protectionEligibilityType
            );

        $this->model->callDoReauthorization();

        $expectedImportedData = [
            'authorization_id' => $authorizationId,
            'payment_status' => Info::PAYMENTSTATUS_COMPLETED,
            'pending_reason' => $pendingReason,
            'protection_eligibility' => $protectionEligibility
        ];

        $this->assertNotContains($protectionEligibilityType, $this->model->getData());
        $this->assertEquals($expectedImportedData, $this->model->getData());
    }

    /**
     * Test replace keys for debug data
     */
    public function testGetDebugReplacePrivateDataKeys()
    {
        $debugReplacePrivateDataKeys = $this->_invokeNvpProperty($this->model, '_debugReplacePrivateDataKeys');
        $this->assertEquals($debugReplacePrivateDataKeys, $this->model->getDebugReplacePrivateDataKeys());
    }

    /**
     * Tests case if obtained response with code 10415 'Transaction has already
     * been completed for this token'. It must does not throws the exception and
     * must returns response array.
     */
    public function testCallTransactionHasBeenCompleted()
    {
        $response =    "\r\n" . 'ACK[7]=Failure&L_ERRORCODE0[5]=10415'
            . '&L_SHORTMESSAGE0[8]=Message.&L_LONGMESSAGE0[15]=Long%20Message.';
        $processableErrors =[10415];
        $this->curl->expects($this->once())
            ->method('read')
            ->willReturn($response);
        $this->model->setProcessableErrors($processableErrors);
        $this->customLoggerMock->expects($this->once())
            ->method('debug');
        $expectedResponse = [
            'ACK' => 'Failure',
            'L_ERRORCODE0' => '10415',
            'L_SHORTMESSAGE0' => 'Message.',
            'L_LONGMESSAGE0' => 'Long Message.'
        ];

        $this->assertEquals($expectedResponse, $this->model->call('some method', ['data' => 'some data']));
    }
}
