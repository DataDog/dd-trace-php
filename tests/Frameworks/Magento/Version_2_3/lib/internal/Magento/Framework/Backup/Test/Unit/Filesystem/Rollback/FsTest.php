<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Backup\Test\Unit\Filesystem\Rollback;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

require_once __DIR__ . '/_files/ioMock.php';

class FsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Backup\Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    private $snapshotMock;

    /**
     * @var \Magento\Framework\Backup\Filesystem\Helper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fsHelperMock;

    /**
     * @var \Magento\Framework\Backup\Filesystem\Rollback\Fs
     */
    private $fs;

    /**
     * @var string
     */
    private $backupPath;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var array
     */
    private $ignorePaths;

    protected function setUp(): void
    {
        $this->backupPath = '/some/test/path';
        $this->rootDir = '/';
        $this->ignorePaths = [];

        $this->objectManager = new ObjectManager($this);
        $this->snapshotMock = $this->getMockBuilder(\Magento\Framework\Backup\Filesystem::class)
            ->setMethods(['getBackupPath', 'getRootDir', 'getIgnorePaths'])
            ->getMock();
        $this->snapshotMock->expects($this->any())
            ->method('getBackupPath')
            ->willReturn($this->backupPath);
        $this->snapshotMock->expects($this->any())
            ->method('getRootDir')
            ->willReturn($this->rootDir);
        $this->snapshotMock->expects($this->any())
            ->method('getIgnorePaths')
            ->willReturn($this->ignorePaths);
        $this->fsHelperMock = $this->getMockBuilder(\Magento\Framework\Backup\Filesystem\Helper::class)
            ->setMethods(['getInfo', 'rm'])
            ->getMock();
        $this->fs = $this->objectManager->getObject(
            \Magento\Framework\Backup\Filesystem\Rollback\Fs::class,
            [
                'snapshotObject' => $this->snapshotMock,
                'fsHelper' => $this->fsHelperMock,
            ]
        );
    }

    /**
     */
    public function testRunNotEnoughPermissions()
    {
        $this->expectException(\Magento\Framework\Backup\Exception\NotEnoughPermissions::class);
        $this->expectExceptionMessage('You need write permissions for: test1, test2');

        $fsInfo = [
            'writable' => false,
            'writableMeta' => ['test1', 'test2'],
        ];

        $this->fsHelperMock->expects($this->once())
            ->method('getInfo')
            ->willReturn($fsInfo);
        $this->fs->run();
    }

    public function testRun()
    {
        $fsInfo = ['writable' => true];

        $this->fsHelperMock->expects($this->once())
            ->method('getInfo')
            ->willReturn($fsInfo);
        $this->fsHelperMock->expects($this->once())
            ->method('rm')
            ->with($this->rootDir, $this->ignorePaths);

        $this->fs->run();
    }
}
