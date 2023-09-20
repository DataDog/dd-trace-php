<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_moduleReaderMock;

    /**
     * @var \Magento\Sales\Model\Config\SchemaLocator
     */
    protected $_locator;

    /**
     * Initialize parameters
     */
    protected function setUp(): void
    {
        $this->_moduleReaderMock = $this->getMockBuilder(
            \Magento\Framework\Module\Dir\Reader::class
        )->disableOriginalConstructor()->getMock();
        $this->_moduleReaderMock->expects(
            $this->once()
        )->method(
            'getModuleDir'
        )->with(
            'etc',
            'Magento_Sales'
        )->willReturn(
            'schema_dir'
        );
        $this->_locator = new \Magento\Sales\Model\Config\SchemaLocator($this->_moduleReaderMock);
    }

    /**
     * Testing that schema has file
     */
    public function testGetSchema()
    {
        $this->assertEquals('schema_dir/sales.xsd', $this->_locator->getSchema());
    }
}
