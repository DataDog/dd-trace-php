<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Directory\Test\Unit\Model\Country\Postcode\Config;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $moduleReaderMock;

    /**
     * @var \Magento\Directory\Model\Country\Postcode\Config\SchemaLocator
     */
    protected $model;

    protected function setUp(): void
    {
        $this->moduleReaderMock = $this->createMock(\Magento\Framework\Module\Dir\Reader::class);
        $this->moduleReaderMock->expects(
            $this->any()
        )->method(
            'getModuleDir'
        )->with(
            'etc',
            'Magento_Directory'
        )->willReturn(
            'schema_dir'
        );

        $this->model = new \Magento\Directory\Model\Country\Postcode\Config\SchemaLocator($this->moduleReaderMock);
    }

    public function testGetSchema()
    {
        $this->assertEquals('schema_dir/zip_codes.xsd', $this->model->getSchema());
    }

    public function testGetPerFileSchema()
    {
        $this->assertEquals('schema_dir/zip_codes.xsd', $this->model->getPerFileSchema());
    }
}
