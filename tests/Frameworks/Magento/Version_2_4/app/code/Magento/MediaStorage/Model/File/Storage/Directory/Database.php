<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\MediaStorage\Model\File\Storage\Directory;

/**
 * @api
 * @since 100.0.2
 *
 * @deprecated Database Media Storage is deprecated
 */
class Database extends \Magento\MediaStorage\Model\File\Storage\Database\AbstractDatabase
{
    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'media_storage_file_storage_directory_database';

    /**
     * Collect errors during sync process
     *
     * @var string[]
     */
    protected $_errors = [];

    /**
     * @var \Magento\MediaStorage\Model\File\Storage\Directory\DatabaseFactory
     */
    protected $_directoryFactory;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\MediaStorage\Helper\File\Storage\Database $coreFileStorageDb
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateModel
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $configuration
     * @param DatabaseFactory $directoryFactory
     * @param \Magento\MediaStorage\Model\ResourceModel\File\Storage\Directory\Database $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param string $connectionName
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\MediaStorage\Helper\File\Storage\Database $coreFileStorageDb,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateModel,
        \Magento\Framework\App\Config\ScopeConfigInterface $configuration,
        \Magento\MediaStorage\Model\File\Storage\Directory\DatabaseFactory $directoryFactory,
        \Magento\MediaStorage\Model\ResourceModel\File\Storage\Directory\Database $resource,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        $connectionName = null,
        array $data = []
    ) {
        $this->_directoryFactory = $directoryFactory;
        parent::__construct(
            $context,
            $registry,
            $coreFileStorageDb,
            $dateModel,
            $configuration,
            $resource,
            $resourceCollection,
            $connectionName,
            $data
        );
        $this->_init(get_class($this->_resource));
    }

    /**
     * Load object data by path
     *
     * @param  string $path
     * @return $this
     */
    public function loadByPath($path)
    {
        /**
         * Clear model data
         * addData() is used because it's needed to clear only db storaged data
         */
        $this->addData(
            ['directory_id' => null, 'name' => null, 'path' => null, 'upload_time' => null, 'parent_id' => null]
        );

        $this->_getResource()->loadByPath($this, $path);
        return $this;
    }

    /**
     * Check if there was errors during sync process
     *
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->_errors);
    }

    /**
     * Retrieve directory parent id
     *
     * @return int
     */
    public function getParentId()
    {
        if (!$this->getData('parent_id')) {
            $parentId = $this->_getResource()->getParentId($this->getPath());
            if (empty($parentId)) {
                $parentId = null;
            }

            $this->setData('parent_id', $parentId);
        }

        return $this->getData('parent_id');
    }

    /**
     * Create directories recursively
     *
     * @param  string $path
     * @return $this
     */
    public function createRecursive($path)
    {
        $directory = $this->_directoryFactory->create()->loadByPath($path);

        if (!$directory->getId()) {
            // phpcs:disable Magento2.Functions.DiscouragedFunction
            $dirName = basename($path);
            // phpcs:disable Magento2.Functions.DiscouragedFunction
            $dirPath = dirname($path);

            if ($dirPath != '.') {
                $parentDir = $this->createRecursive($dirPath);
                $parentId = $parentDir->getId();
            } else {
                $dirPath = '';
                $parentId = null;
            }

            $directory->setName($dirName);
            $directory->setPath($dirPath);
            $directory->setParentId($parentId);
            $directory->save();
        }

        return $directory;
    }

    /**
     * Export directories from storage
     *
     * @param  int $offset
     * @param  int $count
     * @return bool
     */
    public function exportDirectories($offset = 0, $count = 100)
    {
        $offset = (int)$offset >= 0 ? (int)$offset : 0;
        $count = (int)$count >= 1 ? (int)$count : 1;

        $result = $this->_getResource()->exportDirectories($offset, $count);

        if (empty($result)) {
            return false;
        }

        return $result;
    }

    /**
     * Import directories to storage
     *
     * @param  array $dirs
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return $this
     */
    public function importDirectories($dirs)
    {
        if (!is_array($dirs)) {
            return $this;
        }

        $dateSingleton = $this->_date;
        foreach ($dirs as $dir) {
            if (!is_array($dir) || !isset($dir['name']) || !strlen($dir['name'])) {
                continue;
            }

            try {
                $dir['path'] = ltrim($dir['path'] ?? '', './');
                $directory = $this->_directoryFactory->create(['connectionName' => $this->getConnectionName()]);
                $directory->setPath($dir['path']);

                $parentId = $directory->getParentId();
                if ($parentId || $dir['path'] == '') {
                    $directory->setName($dir['name']);
                    $directory->setUploadTime($dateSingleton->date());
                    $directory->save();
                } else {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Parent directory does not exist: %1', $dir['path'])
                    );
                }
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }
        }

        return $this;
    }

    /**
     * Clean directories at storage
     *
     * @return $this
     */
    public function clearDirectories()
    {
        $this->_getResource()->clearDirectories();
        return $this;
    }

    /**
     * Return subdirectories
     *
     * @param string $directory
     * @return array
     */
    public function getSubdirectories($directory)
    {
        $directory = $this->_coreFileStorageDb->getMediaRelativePath($directory);

        return $this->_getResource()->getSubdirectories($directory);
    }

    /**
     * Delete directory from database
     *
     * @param string $dirPath
     * @return $this
     */
    public function deleteDirectory($dirPath)
    {
        $dirPath = $this->_coreFileStorageDb->getMediaRelativePath($dirPath);
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $name = basename($dirPath);
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $path = dirname($dirPath);

        if ('.' == $path) {
            $path = '';
        }

        $this->_getResource()->deleteDirectory($name, $path);

        return $this;
    }
}
