<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Test\Unit\State;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State\CleanupFiles;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverPool;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CleanupFilesTest extends TestCase
{
    /**
     * @var MockObject
     */
    private $filesystem;

    /**
     * @var CleanupFiles
     */
    private $object;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->object = new CleanupFiles($this->filesystem);
    }

    public function testClearCodeGeneratedClasses()
    {
        $dir1 = $this->getDirectoryCleanMock();
        $dir2 = $this->getDirectoryCleanMock();
        $this->filesystem->expects($this->exactly(2))
            ->method('getDirectoryWrite')
            ->willReturnMap(
                [
                    [DirectoryList::GENERATED_CODE, DriverPool::FILE, $dir1],
                    [DirectoryList::GENERATED_METADATA, DriverPool::FILE, $dir2],
                ]
            );
        $this->object->clearCodeGeneratedClasses();
    }

    public function testClearMaterializedViewFiles()
    {
        $static = $this->getDirectoryCleanMock();
        $var = $this->getDirectoryCleanMock(DirectoryList::TMP_MATERIALIZATION_DIR);
        $this->filesystem->expects($this->exactly(2))->method('getDirectoryWrite')->willReturnMap([
            [DirectoryList::STATIC_VIEW, DriverPool::FILE, $static],
            [DirectoryList::VAR_DIR, DriverPool::FILE, $var],
        ]);
        $this->object->clearMaterializedViewFiles();
    }

    /**
     * Gets a mock of directory with expectation to be cleaned
     *
     * @param string|null $subPath
     * @return MockObject
     */
    private function getDirectoryCleanMock($subPath = null)
    {
        $dir = $this->getMockForAbstractClass(WriteInterface::class);
        $dir->expects($this->once())->method('search')->with('*', $subPath)->willReturn(['one', 'two']);
        $dir->expects($this->exactly(2))->method('delete');
        $dir->expects($this->once())->method('isExist')->willReturn(true);
        return $dir;
    }
}
