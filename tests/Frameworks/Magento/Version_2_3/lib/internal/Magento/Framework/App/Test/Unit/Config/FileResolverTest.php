<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Test\Unit\Config;

use Magento\Framework\App\Filesystem\DirectoryList;

class FileResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Files resolver
     *
     * @var \Magento\Framework\App\Config\FileResolver
     */
    protected $model;

    /**
     * Filesystem
     *
     * @var \Magento\Framework\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystem;

    /**
     * File iterator factory
     *
     * @var \Magento\Framework\Config\FileIteratorFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $iteratorFactory;

    /**
     * @var \Magento\Framework\Module\Dir\Reader|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $moduleReader;

    protected function setUp(): void
    {
        $this->iteratorFactory = $this->getMockBuilder(\Magento\Framework\Config\FileIteratorFactory::class)
            ->disableOriginalConstructor()
            ->setConstructorArgs(['getPath'])
            ->getMock();
        $this->filesystem = $this->createPartialMock(\Magento\Framework\Filesystem::class, ['getDirectoryRead']);
        $this->moduleReader = $this->getMockBuilder(\Magento\Framework\Module\Dir\Reader::class)
            ->disableOriginalConstructor()
            ->setConstructorArgs(['getConfigurationFiles'])
            ->getMock();

        $this->model = new \Magento\Framework\App\Config\FileResolver(
            $this->moduleReader,
            $this->filesystem,
            $this->iteratorFactory
        );
    }

    /**
     * Test for get method with primary scope
     *
     * @dataProvider providerGet
     * @param string $filename
     * @param array $fileList
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testGetPrimary($filename, $fileList)
    {
        $scope = 'primary';
        $directory = $this->createMock(\Magento\Framework\Filesystem\Directory\Read::class);
        $directory->expects(
            $this->once()
        )->method(
            'search'
        )->with(
            sprintf('{%1$s,*/%1$s}', $filename)
        )->willReturn(
            $fileList
        );
        $i = 1;
        foreach ($fileList as $file) {
            $directory->expects($this->at($i++))->method('getAbsolutePath')->willReturn($file);
        }
        $this->filesystem->expects(
            $this->once()
        )->method(
            'getDirectoryRead'
        )->with(
            DirectoryList::CONFIG
        )->willReturn(
            $directory
        );
        $this->iteratorFactory->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $fileList
        )->willReturn(
            true
        );
        $this->assertTrue($this->model->get($filename, $scope));
    }

    /**
     * Test for get method with global scope
     *
     * @dataProvider providerGet
     * @param string $filename
     * @param array $fileList
     */
    public function testGetGlobal($filename, $fileList)
    {
        $scope = 'global';
        $this->moduleReader->expects(
            $this->once()
        )->method(
            'getConfigurationFiles'
        )->with(
            $filename
        )->willReturn(
            $fileList
        );
        $this->assertEquals($fileList, $this->model->get($filename, $scope));
    }

    /**
     * Test for get method with default scope
     *
     * @dataProvider providerGet
     * @param string $filename
     * @param array $fileList
     */
    public function testGetDefault($filename, $fileList)
    {
        $scope = 'some_scope';
        $this->moduleReader->expects(
            $this->once()
        )->method(
            'getConfigurationFiles'
        )->with(
            $scope . '/' . $filename
        )->willReturn(
            $fileList
        );
        $this->assertEquals($fileList, $this->model->get($filename, $scope));
    }

    /**
     * Data provider for get tests
     *
     * @return array
     */
    public function providerGet()
    {
        return [
            ['di.xml', ['di.xml', 'anotherfolder/di.xml']],
            ['no_files.xml', []],
            ['one_file.xml', ['one_file.xml']]
        ];
    }
}
