<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\MessageQueue\Test\Unit\Config;

use Magento\Framework\MessageQueue\Code\Generator\Config\RemoteServiceReader\MessageQueue as RemoteServiceReader;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\MessageQueue\Config\Reader\Xml|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $xmlReaderMock;

    /**
     * @var \Magento\Framework\MessageQueue\Config\Reader\Env|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $envReaderMock;

    /**
     * @var RemoteServiceReader|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $remoteServiceReaderMock;

    /**
     * @var \Magento\Framework\Config\CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cacheMock;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $serializerMock;

    protected function setUp(): void
    {
        $this->xmlReaderMock = $this->getMockBuilder(\Magento\Framework\MessageQueue\Config\Reader\Xml::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->envReaderMock = $this->getMockBuilder(\Magento\Framework\MessageQueue\Config\Reader\Env::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->remoteServiceReaderMock = $this
            ->getMockBuilder(
                \Magento\Framework\MessageQueue\Code\Generator\Config\RemoteServiceReader\MessageQueue::class
            )->disableOriginalConstructor()
            ->getMock();
        $this->cacheMock = $this->getMockBuilder(\Magento\Framework\Config\CacheInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->serializerMock = $this->createMock(\Magento\Framework\Serialize\SerializerInterface::class);
    }

    public function testGet()
    {
        $expected = ['someData' => ['someValue', 'someKey' => 'someValue']];
        $this->cacheMock->expects($this->any())
            ->method('load')
            ->willReturn(json_encode($expected));

        $this->serializerMock->expects($this->once())
            ->method('unserialize')
            ->willReturn($expected);

        $this->envReaderMock->expects($this->any())->method('read')->willReturn([]);
        $this->remoteServiceReaderMock->expects($this->any())->method('read')->willReturn([]);
        $this->assertEquals($expected, $this->getModel()->get());
    }

    /**
     * Return Config Data Object
     *
     * @return \Magento\Framework\MessageQueue\Config\Data
     */
    private function getModel()
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        return $objectManager->getObject(
            \Magento\Framework\MessageQueue\Config\Data::class,
            [
                'xmlReader' => $this->xmlReaderMock,
                'cache' => $this->cacheMock,
                'envReader' => $this->envReaderMock,
                'remoteServiceReader' => $this->remoteServiceReaderMock,
                'serializer' => $this->serializerMock,
            ]
        );
    }
}
