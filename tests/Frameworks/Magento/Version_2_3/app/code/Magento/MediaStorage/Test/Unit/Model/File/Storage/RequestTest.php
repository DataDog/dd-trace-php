<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MediaStorage\Test\Unit\Model\File\Storage;

class RequestTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\MediaStorage\Model\File\Storage\Request
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_requestMock;

    /**
     * @var string
     */
    protected $_pathInfo = 'PathInfo';

    protected function setUp(): void
    {
        $path = '..PathInfo';
        $this->_requestMock = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $this->_requestMock->expects($this->once())->method('getPathInfo')->willReturn($path);
        $this->_model = new \Magento\MediaStorage\Model\File\Storage\Request($this->_requestMock);
    }

    protected function tearDown(): void
    {
        unset($this->_model);
        unset($this->_requestMock);
    }

    public function testGetPathInfo()
    {
        $this->assertEquals($this->_pathInfo, $this->_model->getPathInfo());
    }
}
