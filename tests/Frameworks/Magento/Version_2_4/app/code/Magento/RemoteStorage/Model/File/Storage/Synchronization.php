<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\RemoteStorage\Model\File\Storage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\RemoteStorage\Driver\DriverPool as RemoteDriverPool;
use Magento\Framework\Filesystem\DriverPool as LocalDriverPool;
use Magento\RemoteStorage\Model\Config;
use Magento\RemoteStorage\Filesystem;

/**
 * Synchronize files from remote to local file system.
 */
class Synchronization
{
    /**
     * @var bool
     */
    private $isEnabled;

    /**
     * @var WriteInterface
     */
    private $remoteDirectory;

    /**
     * @var WriteInterface
     */
    private $localDirectory;

    /**
     * @param Config $config
     * @param Filesystem $filesystem
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function __construct(Config $config, Filesystem $filesystem)
    {
        $this->isEnabled = $config->isEnabled();
        $this->remoteDirectory = $filesystem->getDirectoryWrite(
            DirectoryList::PUB, RemoteDriverPool::REMOTE
        );
        $this->localDirectory = $filesystem->getDirectoryWrite(
            DirectoryList::PUB, LocalDriverPool::FILE
        );
    }

    /**
     * Synchronize files from remote to local file system.
     * @param string $relativeFileName
     * @return void
     * @throws \LogicException
     */
    public function synchronize($relativeFileName)
    {
        if ($this->isEnabled && $this->remoteDirectory->isExist($relativeFileName)) {
            $file = $this->localDirectory->openFile($relativeFileName, 'w');
            try {
                $file->lock();
                $file->write($this->remoteDirectory->readFile($relativeFileName));
                $file->unlock();
                $file->close();
            } catch (FileSystemException $e) {
                $file->close();
            }
        }
    }
}
