<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\WebapiAsync\Test\Unit\Model\ServiceConfig;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $moduleReaderMock;

    /**
     * @var \Magento\WebapiAsync\Model\ServiceConfig\SchemaLocator
     */
    private $model;

    protected function setUp(): void
    {
        $this->moduleReaderMock = $this->createPartialMock(
            \Magento\Framework\Module\Dir\Reader::class,
            ['getModuleDir']
        );
        $this->moduleReaderMock->expects(
            $this->any()
        )->method(
            'getModuleDir'
        )->with(
            'etc',
            'Magento_WebapiAsync'
        )->willReturn(
            'schema_dir'
        );

        $this->model = new \Magento\WebapiAsync\Model\ServiceConfig\SchemaLocator($this->moduleReaderMock);
    }

    public function testGetSchema()
    {
        $this->assertEquals('schema_dir/webapi_async.xsd', $this->model->getSchema());
    }

    public function testGetPerFileSchema()
    {
        $this->assertNull($this->model->getPerFileSchema());
    }
}
