<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\ImportExport\Model\Export\AbstractEntity
 */
namespace Magento\ImportExport\Test\Unit\Model\Export;

class EntityAbstractTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Test for setter and getter of file name property
     *
     * @covers \Magento\ImportExport\Model\Export\AbstractEntity::getFileName
     * @covers \Magento\ImportExport\Model\Export\AbstractEntity::setFileName
     */
    public function testGetFileNameAndSetFileName()
    {
        /** @var $model \Magento\ImportExport\Model\Export\AbstractEntity */
        $model = $this->getMockForAbstractClass(
            \Magento\ImportExport\Model\Export\AbstractEntity::class,
            [],
            'Stub_UnitTest_Magento_ImportExport_Model_Export_Entity_TestSetAndGet',
            false
        );

        $testFileName = 'test_file_name';

        $fileName = $model->getFileName();
        $this->assertNull($fileName);

        $model->setFileName($testFileName);
        $this->assertEquals($testFileName, $model->getFileName());

        $fileName = $model->getFileName();
        $this->assertEquals($testFileName, $fileName);
    }
}
