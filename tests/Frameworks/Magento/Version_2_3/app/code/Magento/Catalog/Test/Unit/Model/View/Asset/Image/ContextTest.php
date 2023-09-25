<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\View\Asset\Image;

use Magento\Catalog\Model\Product\Media\ConfigInterface;
use Magento\Catalog\Model\View\Asset\Image\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Class ContextTest
 */
class ContextTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Context
     */
    protected $model;

    /**
     * @var WriteInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $mediaDirectory;

    /**
     * @var ContextInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $mediaConfig;

    /**
     * @var Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystem;

    protected function setUp(): void
    {
        $this->mediaConfig = $this->getMockBuilder(ConfigInterface::class)->getMockForAbstractClass();
        $this->mediaConfig->expects($this->any())->method('getBaseMediaPath')->willReturn('catalog/product');
        $this->mediaDirectory = $this->getMockBuilder(WriteInterface::class)->getMockForAbstractClass();
        $this->mediaDirectory->expects($this->once())->method('create')->with('catalog/product');
        $this->filesystem = $this->getMockBuilder(Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filesystem->expects($this->once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);
        $this->model = new Context(
            $this->mediaConfig,
            $this->filesystem
        );
    }

    public function testGetPath()
    {
        $path = '/var/www/html/magento2ce/pub/media/catalog/product';
        $this->mediaDirectory->expects($this->once())
            ->method('getAbsolutePath')
            ->with('catalog/product')
            ->willReturn($path);

        $this->assertEquals($path, $this->model->getPath());
    }

    public function testGetUrl()
    {
        $baseUrl = 'http://localhost/pub/media/catalog/product';
        $this->mediaConfig->expects($this->once())->method('getBaseMediaUrl')->willReturn($baseUrl);

        $this->assertEquals($baseUrl, $this->model->getBaseUrl());
    }
}
