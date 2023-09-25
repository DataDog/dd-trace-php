<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Code\Test\Unit\Model\File\Validator;

use Magento\Framework\Phrase;

class NotProtectedExtensionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\MediaStorage\Model\File\Validator\NotProtectedExtension
     */
    protected $_model;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_scopeConfig;

    /**
     * @var string
     */
    protected $_protectedList = 'exe,php,jar';

    protected function setUp(): void
    {
        $this->_scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->_scopeConfig->expects(
            $this->atLeastOnce()
        )->method(
            'getValue'
        )->with(
            $this->equalTo(
                \Magento\MediaStorage\Model\File\Validator\NotProtectedExtension::XML_PATH_PROTECTED_FILE_EXTENSIONS
            ),
            $this->equalTo(\Magento\Store\Model\ScopeInterface::SCOPE_STORE),
            $this->equalTo(null)
        )->willReturn(
            $this->_protectedList
        );
        $this->_model = new \Magento\MediaStorage\Model\File\Validator\NotProtectedExtension($this->_scopeConfig);
    }

    public function testGetProtectedFileExtensions()
    {
        $this->assertEquals($this->_protectedList, $this->_model->getProtectedFileExtensions());
    }

    public function testInitialization()
    {
        $property = new \ReflectionProperty(
            \Magento\MediaStorage\Model\File\Validator\NotProtectedExtension::class,
            '_messageTemplates'
        );
        $property->setAccessible(true);
        $defaultMess = [
            'protectedExtension' => new Phrase('File with an extension "%value%" is protected and cannot be uploaded'),
        ];
        $this->assertEquals($defaultMess, $property->getValue($this->_model));

        $property = new \ReflectionProperty(
            \Magento\MediaStorage\Model\File\Validator\NotProtectedExtension::class,
            '_protectedFileExtensions'
        );
        $property->setAccessible(true);
        $protectedList = ['exe', 'php', 'jar'];
        $this->assertEquals($protectedList, $property->getValue($this->_model));
    }

    public function testIsValid()
    {
        $this->assertTrue($this->_model->isValid('html'));
        $this->assertTrue($this->_model->isValid('jpg'));
        $this->assertFalse($this->_model->isValid('php'));
        $this->assertFalse($this->_model->isValid('jar'));
        $this->assertFalse($this->_model->isValid('exe'));
    }
}
