<?php
/**
 * Test Webapi module exception.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Webapi\Test\Unit;

class ExceptionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Test Webapi exception construct.
     */
    public function testConstruct()
    {
        $code = 1111;
        $details = ['key1' => 'value1', 'key2' => 'value2'];
        $apiException = new \Magento\Framework\Webapi\Exception(
            __('Message'),
            $code,
            \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED,
            $details
        );
        $this->assertEquals(
            $apiException->getHttpCode(),
            \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED,
            'Exception code is set incorrectly in construct.'
        );
        $this->assertEquals(
            $apiException->getMessage(),
            'Message',
            'Exception message is set incorrectly in construct.'
        );
        $this->assertEquals($apiException->getCode(), $code, 'Exception code is set incorrectly in construct.');
        $this->assertEquals($apiException->getDetails(), $details, 'Details are set incorrectly in construct.');
    }

    /**
     * Test Webapi exception construct with invalid data.
     *
     * @dataProvider providerForTestConstructInvalidHttpCode
     */
    public function testConstructInvalidHttpCode($httpCode)
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage("The specified HTTP code \"{$httpCode}\" is invalid.");
        /** Create \Magento\Framework\Webapi\Exception object with invalid code. */
        /** Valid codes range is from 400 to 599. */
        new \Magento\Framework\Webapi\Exception(__('Message'), 0, $httpCode);
    }

    public function testGetOriginatorSender()
    {
        $apiException = new \Magento\Framework\Webapi\Exception(
            __('Message'),
            0,
            \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED
        );
        /** Check that Webapi \Exception object with code 401 matches Sender originator.*/
        $this->assertEquals(
            \Magento\Webapi\Model\Soap\Fault::FAULT_CODE_SENDER,
            $apiException->getOriginator(),
            'Wrong Sender originator detecting.'
        );
    }

    public function testGetOriginatorReceiver()
    {
        $apiException = new \Magento\Framework\Webapi\Exception(
            __('Message'),
            0,
            \Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR
        );
        /** Check that Webapi \Exception object with code 500 matches Receiver originator.*/
        $this->assertEquals(
            \Magento\Webapi\Model\Soap\Fault::FAULT_CODE_RECEIVER,
            $apiException->getOriginator(),
            'Wrong Receiver originator detecting.'
        );
    }

    /**
     * Data provider for testConstructInvalidCode.
     *
     * @return array
     */
    public function providerForTestConstructInvalidHttpCode()
    {
        //Each array contains invalid \Exception code.
        return [[300], [600]];
    }
}
