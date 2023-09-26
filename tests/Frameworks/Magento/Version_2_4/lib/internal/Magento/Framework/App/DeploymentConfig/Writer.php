<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\App\DeploymentConfig;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Phrase;

/**
 * Deployment configuration writer to files: env.php, config.php.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Writer
{
    /**
     * Deployment config reader
     *
     * @var Reader
     */
    private $reader;

    /**
     * Application filesystem
     *
     * @var Filesystem
     */
    private $filesystem;

    /**
     * Formatter
     *
     * @var Writer\FormatterInterface
     */
    private $formatter;

    /**
     * @var ConfigFilePool
     */
    private $configFilePool;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * The parser of comments from configuration files.
     *
     * @var CommentParser
     */
    private $commentParser;

    /**
     * @param Reader $reader
     * @param Filesystem $filesystem
     * @param ConfigFilePool $configFilePool
     * @param DeploymentConfig $deploymentConfig
     * @param Writer\FormatterInterface $formatter
     * @param CommentParser $commentParser The parser of comments from configuration files
     */
    public function __construct(
        Reader $reader,
        Filesystem $filesystem,
        ConfigFilePool $configFilePool,
        DeploymentConfig $deploymentConfig,
        Writer\FormatterInterface $formatter = null,
        CommentParser $commentParser = null
    ) {
        $this->reader = $reader;
        $this->filesystem = $filesystem;
        $this->configFilePool = $configFilePool;
        $this->deploymentConfig = $deploymentConfig;
        $this->formatter = $formatter ?: new Writer\PhpFormatter();
        $this->commentParser = $commentParser ?: new CommentParser($filesystem, $configFilePool);
    }

    /**
     * Check if configuration file is writable
     *
     * @return bool
     */
    public function checkIfWritable()
    {
        $configDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::CONFIG);
        foreach ($this->reader->getFiles() as $file) {
            if (!$configDirectory->isWritable($file)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Saves config in specified file.
     * $pool option is deprecated since version 2.2.0.
     *
     * Usage:
     * ```php
     * saveConfig(
     *      [
     *          ConfigFilePool::APP_ENV => ['some' => 'value'],
     *      ],
     *      true,
     *      null,
     *      [],
     *      false
     * )
     * ```
     *
     * @param array $data The data to be saved
     * @param bool $override Whether values should be overridden
     * @param string $pool The file pool (deprecated)
     * @param array $comments The array of comments
     * @param bool $lock Whether the file should be locked while writing
     * @return void
     * @throws FileSystemException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function saveConfig(array $data, $override = false, $pool = null, array $comments = [], bool $lock = false)
    {
        foreach ($data as $fileKey => $config) {
            $paths = $this->configFilePool->getPaths();

            if (isset($paths[$fileKey])) {
                $currentData = $this->reader->load($fileKey);
                $currentComments = $this->commentParser->execute($paths[$fileKey]);

                if ($currentData) {
                    if ($override) {
                        $config = array_merge($currentData, $config);
                    } else {
                        $config = array_replace_recursive($currentData, $config);
                    }
                }

                $comments = array_merge($currentComments, $comments);

                $contents = $this->formatter->format($config, $comments);
                try {
                    $writeFilePath = $paths[$fileKey];
                    $directoryWrite = $this->filesystem->getDirectoryWrite(DirectoryList::CONFIG);
                    if ($directoryWrite instanceof Write) {
                        $directoryWrite->writeFile($writeFilePath, $contents, 'w+', $lock);
                    } else {
                        $directoryWrite->writeFile($writeFilePath, $contents);
                    }
                } catch (FileSystemException $e) {
                    throw new FileSystemException(
                        new Phrase('The "%1" deployment config file isn\'t writable.', [$paths[$fileKey]])
                    );
                }
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate(
                        $this->filesystem->getDirectoryRead(DirectoryList::CONFIG)->getAbsolutePath($paths[$fileKey])
                    );
                }
            }
        }
        $this->deploymentConfig->resetData();
    }
}
