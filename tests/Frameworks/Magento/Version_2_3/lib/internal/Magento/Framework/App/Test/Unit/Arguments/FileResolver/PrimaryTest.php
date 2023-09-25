<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Arguments\FileResolver;

use Magento\Framework\App\Filesystem\DirectoryList;

class PrimaryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array $fileList
     * @param string $scope
     * @param string $filename
     * @dataProvider getMethodDataProvider
     */
    public function testGet(array $fileList, $scope, $filename)
    {
        $directory = $this->createMock(\Magento\Framework\Filesystem\Directory\Read::class);
        $filesystem = $this->createMock(\Magento\Framework\Filesystem::class);
        $iteratorFactory = $this->createPartialMock(\Magento\Framework\Config\FileIteratorFactory::class, ['create']);

        $filesystem->expects(
            $this->once()
        )->method(
            'getDirectoryRead'
        )->with(
            DirectoryList::CONFIG
        )->willReturn(
            $directory
        );

        $directory->expects($this->once())->method('search')->willReturn($fileList);

        $iteratorFactory->expects($this->once())->method('create')->willReturn(true);

        $model = new \Magento\Framework\App\Arguments\FileResolver\Primary($filesystem, $iteratorFactory);

        $this->assertTrue($model->get($filename, $scope));
    }

    /**
     * @return array
     */
    public function getMethodDataProvider()
    {
        return [[['config/di.xml', 'config/some_config/di.xml'], 'primary', 'di.xml']];
    }
}
