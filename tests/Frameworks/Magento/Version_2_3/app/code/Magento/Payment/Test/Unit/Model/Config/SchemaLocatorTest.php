<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Test\Unit\Model\Config;

use \Magento\Payment\Model\Config\SchemaLocator;

class SchemaLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Payment\Model\Config\SchemaLocator
     */
    protected $model;

    const MODULE_DIR_PATH = '/path/to/payment/schema';

    protected function setUp(): void
    {
        $moduleReader = $this->getMockBuilder(
            \Magento\Framework\Module\Dir\Reader::class
        )->disableOriginalConstructor()->setMethods([])->getMock();
        $moduleReader->expects($this->once())->method('getModuleDir')->with('etc', 'Magento_Payment')->willReturn(
            self::MODULE_DIR_PATH
        );
        $this->model = new SchemaLocator($moduleReader);
    }

    public function testGetSchema()
    {
        $this->assertEquals(
            self::MODULE_DIR_PATH . '/' . SchemaLocator::MERGED_CONFIG_SCHEMA,
            $this->model->getSchema()
        );
    }

    public function testGetPerFileSchema()
    {
        $this->assertEquals(
            self::MODULE_DIR_PATH . '/' . SchemaLocator::PER_FILE_VALIDATION_SCHEMA,
            $this->model->getPerFileSchema()
        );
    }
}
