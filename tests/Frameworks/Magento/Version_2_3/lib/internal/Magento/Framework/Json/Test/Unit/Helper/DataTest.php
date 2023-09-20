<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Json\Test\Unit\Helper;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $helper;

    /** @var \Magento\Framework\Json\EncoderInterface | \PHPUnit\Framework\MockObject\MockObject */
    protected $jsonEncoderMock;

    /** @var \Magento\Framework\Json\DecoderInterface | \PHPUnit\Framework\MockObject\MockObject  */
    protected $jsonDecoderMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->jsonEncoderMock = $this->getMockBuilder(\Magento\Framework\Json\EncoderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonDecoderMock = $this->getMockBuilder(\Magento\Framework\Json\DecoderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->helper = $objectManager->getObject(
            \Magento\Framework\Json\Helper\Data::class,
            [
                'jsonEncoder' => $this->jsonEncoderMock,
                'jsonDecoder' => $this->jsonDecoderMock,
            ]
        );
    }

    public function testJsonEncode()
    {
        $expected = '"valueToEncode"';
        $valueToEncode = 'valueToEncode';
        $this->jsonEncoderMock->expects($this->once())
            ->method('encode')
            ->willReturn($expected);
        $this->assertEquals($expected, $this->helper->jsonEncode($valueToEncode));
    }

    public function testJsonDecode()
    {
        $expected = '"valueToDecode"';
        $valueToDecode = 'valueToDecode';
        $this->jsonDecoderMock->expects($this->once())
            ->method('decode')
            ->willReturn($expected);
        $this->assertEquals($expected, $this->helper->jsonDecode($valueToDecode));
    }
}
