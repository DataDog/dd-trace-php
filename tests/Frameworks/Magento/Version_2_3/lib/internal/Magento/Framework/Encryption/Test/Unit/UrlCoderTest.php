<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Encryption\Test\Unit;

class UrlCoderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Encryption\UrlCoder
     */
    protected $_urlCoder;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_urlMock;

    /**
     * @var string
     */
    protected $_url = 'http://example.com';

    /**
     * @var string
     */
    protected $_encodeUrl = 'aHR0cDovL2V4YW1wbGUuY29t';

    protected function setUp(): void
    {
        $this->_urlMock = $this->createMock(\Magento\Framework\UrlInterface::class);
        $this->_urlCoder = new \Magento\Framework\Encryption\UrlCoder($this->_urlMock);
    }

    public function testDecode()
    {
        $this->_urlMock->expects(
            $this->once()
        )->method(
            'sessionUrlVar'
        )->with(
            $this->_url
        )->willReturn(
            'expected'
        );
        $this->assertEquals('expected', $this->_urlCoder->decode($this->_encodeUrl));
    }

    public function testEncode()
    {
        $this->assertEquals($this->_encodeUrl, $this->_urlCoder->encode($this->_url));
    }
}
