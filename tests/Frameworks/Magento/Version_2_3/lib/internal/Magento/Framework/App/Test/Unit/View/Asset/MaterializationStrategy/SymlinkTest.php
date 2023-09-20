<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App\Test\Unit\View\Asset\MaterializationStrategy;

use \Magento\Framework\App\View\Asset\MaterializationStrategy\Symlink;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Asset;

class SymlinkTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Symlink
     */
    private $symlinkPublisher;

    protected function setUp(): void
    {
        $this->symlinkPublisher = new Symlink;
    }

    public function testPublishFile()
    {
        $rootDir = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMock();
        $targetDir = $this->getMockBuilder(\Magento\Framework\Filesystem\Directory\WriteInterface::class)
            ->getMock();
        $sourcePath = 'source/path/file';
        $destinationPath = 'destination/path/file';

        $rootDir->expects($this->once())
            ->method('createSymlink')
            ->with(
                $sourcePath,
                $destinationPath,
                $targetDir
            )->willReturn(true);

        $this->assertTrue($this->symlinkPublisher->publishFile($rootDir, $targetDir, $sourcePath, $destinationPath));
    }

    /**
     * @dataProvider sourceFileDataProvider
     */
    public function testIsSupported($path, $expectation)
    {
        $asset = $this->getMockBuilder(\Magento\Framework\View\Asset\LocalInterface::class)
            ->setMethods([])
            ->getMock();
        $asset->expects($this->once())
            ->method('getSourceFile')
            ->willReturn($path);
        $this->assertEquals($expectation, $this->symlinkPublisher->isSupported($asset));
    }

    /**
     * @return array
     */
    public function sourceFileDataProvider()
    {
        return [
            ['path/to/file', true],
            [DirectoryList::TMP_MATERIALIZATION_DIR . '/path/to/file', false]
        ];
    }
}
