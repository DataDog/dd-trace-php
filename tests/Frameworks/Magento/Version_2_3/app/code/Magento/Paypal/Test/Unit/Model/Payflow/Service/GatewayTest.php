<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Test\Unit\Model\Payflow\Service;

use Magento\Framework\DataObject;
use Magento\Framework\HTTP\ZendClient;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Math\Random;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Paypal\Model\Payflow\Service\Gateway;
use Psr\Log\LoggerInterface;

/**
 * Class GatewayTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GatewayTest extends \PHPUnit\Framework\TestCase
{
    /** @var Gateway|\PHPUnit\Framework\MockObject\MockObject */
    protected $object;

    /** @var ZendClientFactory|\PHPUnit\Framework\MockObject\MockObject */
    protected $httpClientFactoryMock;

    /** @var Random|\PHPUnit\Framework\MockObject\MockObject */
    protected $mathRandomMock;

    /** @var Logger|\PHPUnit\Framework\MockObject\MockObject */
    protected $loggerMock;

    /** @var ZendClient|\PHPUnit\Framework\MockObject\MockObject */
    protected $zendClientMock;

    protected function setUp(): void
    {
        $this->httpClientFactoryMock = $this->getMockBuilder(ZendClientFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->zendClientMock = $this->getMockBuilder(ZendClient::class)
            ->setMethods(['request', 'setUri'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->httpClientFactoryMock->expects(static::once())
            ->method('create')
            ->willReturn($this->zendClientMock);
        $this->mathRandomMock = $this->getMockBuilder(Random::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggerMock = $this->getMockBuilder(Logger::class)
            ->setConstructorArgs([$this->getMockForAbstractClass(LoggerInterface::class)])
            ->setMethods(['debug'])
            ->getMock();

        $this->object = new Gateway(
            $this->httpClientFactoryMock,
            $this->mathRandomMock,
            $this->loggerMock
        );
    }

    public function testPostRequestOk()
    {
        $configMap = [
            ['getDebugReplacePrivateDataKeys', null, ['masked']],
            ['debug', null, true]
        ];
        $expectedResponse = 'RESULT=0&RESPMSG=Approved&SECURETOKEN=8ZIaw2&SECURETOKENID=2481d53';

        /** @var ConfigInterface|\PHPUnit\Framework\MockObject\MockObject $configInterfaceMock */
        $configInterfaceMock = $this->getMockBuilder(ConfigInterface::class)
            ->getMockForAbstractClass();
        $zendResponseMock = $this->getMockBuilder(\Zend_Http_Response::class)
            ->setMethods(['getBody'])
            ->disableOriginalConstructor()
            ->getMock();
        $zendResponseMock->expects(static::once())
            ->method('getBody')
            ->willReturn($expectedResponse);
        $this->zendClientMock->expects(static::once())
            ->method('request')
            ->willReturn($zendResponseMock);

        $configInterfaceMock->expects(static::any())
            ->method('getValue')
            ->willReturnMap($configMap);
        $this->loggerMock->expects(static::once())
            ->method('debug');

        $object = new DataObject();

        $result = $this->object->postRequest($object, $configInterfaceMock);

        static::assertInstanceOf(DataObject::class, $result);
        static::assertArrayHasKey('result_code', $result->getData());
    }

    /**
     */
    public function testPostRequestFail()
    {
        $this->expectException(\Zend_Http_Client_Exception::class);

        /** @var ConfigInterface|\PHPUnit\Framework\MockObject\MockObject $configInterfaceMock */
        $configInterfaceMock = $this->getMockBuilder(ConfigInterface::class)
            ->getMockForAbstractClass();
        $zendResponseMock = $this->getMockBuilder(\Zend_Http_Response::class)
            ->setMethods(['getBody'])
            ->disableOriginalConstructor()
            ->getMock();
        $zendResponseMock->expects(static::never())
            ->method('getBody');
        $this->zendClientMock->expects(static::once())
            ->method('request')
            ->willThrowException(new \Zend_Http_Client_Exception());

        $object = new DataObject();
        $this->object->postRequest($object, $configInterfaceMock);
    }
}
