<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Captcha\Test\Unit\Helper\Adminhtml;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Captcha\Helper\Adminhtml\Data | |PHPUnit\Framework\MockObject\MockObject
     */
    protected $_model;

    /**
     * setUp
     */
    protected function setUp(): void
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $className = \Magento\Captcha\Helper\Adminhtml\Data::class;
        $arguments = $objectManagerHelper->getConstructArguments($className);

        $backendConfig = $arguments['backendConfig'];
        $backendConfig->expects(
            $this->any()
        )->method(
            'getValue'
        )->with(
            'admin/captcha/qwe'
        )->willReturn(
            '1'
        );

        $filesystemMock = $arguments['filesystem'];
        $directoryMock = $this->createMock(\Magento\Framework\Filesystem\Directory\Write::class);

        $filesystemMock->expects($this->any())->method('getDirectoryWrite')->willReturn($directoryMock);
        $directoryMock->expects($this->any())->method('getAbsolutePath')->willReturnArgument(0);

        $this->_model = $objectManagerHelper->getObject($className, $arguments);
    }

    public function testGetConfig()
    {
        $this->assertEquals('1', $this->_model->getConfig('qwe'));
    }

    /**
     * @covers \Magento\Captcha\Helper\Adminhtml\Data::_getWebsiteCode
     */
    public function testGetWebsiteId()
    {
        $this->assertStringEndsWith('/admin/', $this->_model->getImgDir());
    }
}
