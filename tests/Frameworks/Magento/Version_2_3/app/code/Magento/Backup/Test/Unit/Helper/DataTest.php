<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backup\Test\Unit\Helper;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backup\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Framework\Filesystem | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = $this->getMockBuilder(\Magento\Framework\Filesystem::class)->disableOriginalConstructor()
            ->getMock();

        $this->filesystem->expects($this->any())
            ->method('getDirectoryRead')
            ->willReturnCallback(function ($code) {
                $dir = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\Directory\ReadInterface::class);
                $dir->expects($this->any())
                    ->method('getAbsolutePath')
                    ->willReturnCallback(function ($path) use ($code) {
                        $path = empty($path) ? $path : '/' . $path;
                        return rtrim($code, '/') . $path;
                    });
                return $dir;
            });

        $this->helper = (new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this))
            ->getObject(
                \Magento\Backup\Helper\Data::class,
                ['filesystem' => $this->filesystem]
            );
    }

    public function testGetBackupIgnorePaths()
    {
        $this->assertEquals(
            [
                '.git',
                '.svn',
                MaintenanceMode::FLAG_DIR . '/' . MaintenanceMode::FLAG_FILENAME,
                DirectoryList::SESSION,
                DirectoryList::CACHE,
                DirectoryList::LOG,
                DirectoryList::VAR_DIR . '/full_page_cache',
                DirectoryList::VAR_DIR . '/locks',
                DirectoryList::VAR_DIR . '/report',
            ],
            $this->helper->getBackupIgnorePaths()
        );
    }

    public function testGetRollbackIgnorePaths()
    {
        $this->assertEquals(
            [
                '.svn',
                '.git',
                'var/' . MaintenanceMode::FLAG_FILENAME,
                DirectoryList::SESSION,
                DirectoryList::LOG,
                DirectoryList::VAR_DIR . '/locks',
                DirectoryList::VAR_DIR . '/report',
                DirectoryList::ROOT . '/errors',
                DirectoryList::ROOT . '/index.php',
            ],
            $this->helper->getRollbackIgnorePaths()
        );
    }
}
