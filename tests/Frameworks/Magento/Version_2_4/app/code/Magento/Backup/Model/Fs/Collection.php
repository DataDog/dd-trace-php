<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backup\Model\Fs;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Backup data collection
 * @api
 * @since 100.0.2
 */
class Collection extends \Magento\Framework\Data\Collection\Filesystem
{
    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $_varDirectory;

    /**
     * Folder, where all backups are stored
     *
     * @var string
     */
    protected $_path = 'backups';

    /**
     * Backup data
     *
     * @var \Magento\Backup\Helper\Data
     */
    protected $_backupData = null;

    /**
     * Backup model
     *
     * @var \Magento\Backup\Model\Backup
     */
    protected $_backup = null;

    /**
     * @var \Magento\Framework\Filesystem
     */
    private $_filesystem;
    /**
     * @param \Magento\Framework\Data\Collection\EntityFactory $entityFactory
     * @param \Magento\Backup\Helper\Data $backupData
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Backup\Model\Backup $backup
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        \Magento\Backup\Helper\Data $backupData,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Backup\Model\Backup $backup
    ) {
        $this->_backupData = $backupData;
        parent::__construct($entityFactory, $filesystem);

        $this->_filesystem = $filesystem;
        $this->_backup = $backup;
        $this->_varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);

        $this->_hideBackupsForApache();

        // set collection specific params
        $extensions = $this->_backupData->getExtensions();

        foreach ($extensions as $value) {
            $extensions[] = '(' . preg_quote($value, '/') . ')';
        }
        $extensions = implode('|', $extensions);

        $this->_varDirectory->create($this->_path);
        $path = rtrim($this->_varDirectory->getAbsolutePath($this->_path), '/') . '/';
        $this->setOrder(
            'time',
            self::SORT_ORDER_DESC
        )->addTargetDir(
            $path
        )->setFilesFilter(
            '/^[a-z0-9\-\_]+\.' . $extensions . '$/'
        )->setCollectRecursively(
            false
        );
    }

    /**
     * Create .htaccess file and deny backups directory access from web
     *
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function _hideBackupsForApache()
    {
        $filename = '.htaccess';
        $driver = $this->_varDirectory->getDriver();
        $absolutePath = $driver->getAbsolutePath($this->_varDirectory->getAbsolutePath(), $filename);
        if (!$driver->isFile($absolutePath)) {
            $resource = $driver->fileOpen($absolutePath, 'w+');
            $driver->fileWrite($resource, 'deny from all');
            $driver->fileClose($resource);
        }
    }

    /**
     * Get backup-specific data from model for each row
     *
     * @param string $filename
     * @return array
     */
    protected function _generateRow($filename)
    {
        $row = parent::_generateRow($filename);
        foreach ($this->_backup->load(
            $row['basename'],
            $this->_varDirectory->getAbsolutePath($this->_path)
        )->getData() as $key => $value) {
            $row[$key] = $value;
        }
        $row['size'] = $this->_varDirectory->stat($this->_varDirectory->getRelativePath($filename))['size'];
        if (isset($row['display_name']) && $row['display_name'] == '') {
            $row['display_name'] = 'WebSetupWizard';
        }
        $row['id'] = $row['time'] . '_' . $row['type']
            . (isset($row['display_name']) ? '_' . $row['display_name'] : '');
        return $row;
    }
}
