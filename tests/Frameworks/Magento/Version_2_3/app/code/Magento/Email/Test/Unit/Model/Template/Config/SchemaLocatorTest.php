<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Email\Test\Unit\Model\Template\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Email\Model\Template\Config\SchemaLocator
     */
    protected $_model;

    /**
     * @var \Magento\Framework\Module\Dir\Reader|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_moduleReader;

    protected function setUp(): void
    {
        $this->_moduleReader = $this->createPartialMock(\Magento\Framework\Module\Dir\Reader::class, ['getModuleDir']);
        $this->_moduleReader->expects(
            $this->once()
        )->method(
            'getModuleDir'
        )->with(
            'etc',
            'Magento_Email'
        )->willReturn(
            'fixture_dir'
        );
        $this->_model = new \Magento\Email\Model\Template\Config\SchemaLocator($this->_moduleReader);
    }

    public function testGetSchema()
    {
        $actualResult = $this->_model->getSchema();
        $this->assertEquals('fixture_dir/email_templates.xsd', $actualResult);
        // Makes sure the value is calculated only once
        $this->assertEquals($actualResult, $this->_model->getSchema());
    }

    public function testGetPerFileSchema()
    {
        $actualResult = $this->_model->getPerFileSchema();
        $this->assertEquals('fixture_dir/email_templates.xsd', $actualResult);
        // Makes sure the value is calculated only once
        $this->assertEquals($actualResult, $this->_model->getPerFileSchema());
    }
}
