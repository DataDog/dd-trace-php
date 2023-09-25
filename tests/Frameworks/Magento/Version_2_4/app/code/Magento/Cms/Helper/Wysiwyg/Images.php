<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Cms\Helper\Wysiwyg;

use Exception;
use InvalidArgumentException;
use Magento\Backend\Helper\Data;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Helper\Storage;

/**
 * Wysiwyg Images Helper.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Images extends AbstractHelper
{
    /**
     * Image directory subpath relative to media directory
     *
     * @var string
     */
    private $imageDirectorySubpath;

    /**
     * Current directory path
     *
     * @var string
     */
    protected $_currentPath;

    /**
     * Current directory URL
     *
     * @var string
     */
    protected $_currentUrl;

    /**
     * Currently selected store ID if applicable
     *
     * @var int
     */
    protected $_storeId;

    /**
     * @var Write
     */
    protected $_directory;

    /**
     * Adminhtml data
     *
     * @var Data
     */
    protected $_backendData;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * String escaper
     *
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var Read
     */
    private $_readDirectory;

    /**
     * Construct
     *
     * @param Context $context
     * @param Data $backendData
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     * @param Escaper $escaper
     */
    public function __construct(
        Context $context,
        Data $backendData,
        Filesystem $filesystem,
        StoreManagerInterface $storeManager,
        Escaper $escaper
    ) {
        parent::__construct($context);
        $this->_backendData = $backendData;
        $this->_storeManager = $storeManager;
        $this->escaper = $escaper;

        $this->_directory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->_directory->create($this->getStorageRoot());
        $this->_readDirectory = $filesystem->getDirectoryReadByPath($this->getStorageRoot());
    }

    /**
     * Set a specified store ID value
     *
     * @param int $store
     * @return $this
     */
    public function setStoreId($store)
    {
        $this->_storeId = $store;
        return $this;
    }

    /**
     * Images Storage root directory
     *
     * @return string
     */
    public function getStorageRoot()
    {
        return $this->_directory->getAbsolutePath($this->getStorageRootSubpath());
    }

    /**
     * Get image storage root subpath.  User is unable to traverse outside of this subpath in media gallery
     *
     * @return string
     */
    public function getStorageRootSubpath()
    {
        return '';
    }

    /**
     * Images Storage base URL
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }

    /**
     * Ext Tree node key name
     *
     * @return string
     */
    public function getTreeNodeName()
    {
        return 'node';
    }

    /**
     * Encode path to HTML element id
     *
     * @param string $path Path to file/directory
     * @return string
     */
    public function convertPathToId($path)
    {
        $path = $path === null ? '' : str_replace($this->getStorageRoot(), '', $path);
        return $this->idEncode($path);
    }

    /**
     * Decode HTML element id.
     *
     * @param string $id
     * @return string
     * @throws InvalidArgumentException
     */
    public function convertIdToPath($id)
    {
        if ($id === Storage::NODE_ROOT) {
            return $this->getStorageRoot();
        } else {
            $path = $this->getStorageRoot() . $this->idDecode($id);

            try {
                $this->_readDirectory->getAbsolutePath($path);
            } catch (Exception $e) {
                throw new InvalidArgumentException('Path is invalid');
            }

            return $path;
        }
    }

    /**
     * Check whether using static URLs is allowed
     *
     * @return bool
     */
    public function isUsingStaticUrlsAllowed()
    {
        $checkResult = (object)[];
        $checkResult->isAllowed = false;
        $this->_eventManager->dispatch(
            'cms_wysiwyg_images_static_urls_allowed',
            ['result' => $checkResult, 'store_id' => $this->_storeId]
        );
        return $checkResult->isAllowed;
    }

    /**
     * Prepare Image insertion declaration for Wysiwyg or textarea(as_is mode)
     *
     * @param string $filename Filename transferred via Ajax
     * @param bool $renderAsTag Leave image HTML as is or transform it to controller directive
     * @return string
     */
    public function getImageHtmlDeclaration($filename, $renderAsTag = false)
    {
        $fileUrl = $this->getCurrentUrl() . $filename;
        $mediaUrl = $this->_storeManager->getStore($this->_storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $mediaPath = str_replace($mediaUrl, '', $fileUrl);
        $directive = sprintf('{{media url="%s"}}', $mediaPath);
        if ($renderAsTag) {
            $src = $this->isUsingStaticUrlsAllowed() ? $fileUrl : $this->escaper->escapeHtml($directive);
            $html = sprintf('<img src="%s" alt="" />', $src);
        } else {
            if ($this->isUsingStaticUrlsAllowed()) {
                $html = $fileUrl;
            } else {
                $directive = $this->urlEncoder->encode($directive);
                $html = $this->_backendData->getUrl(
                    'cms/wysiwyg/directive',
                    [
                        '___directive' => $directive,
                        '_escape_params' => false,
                    ]
                );
            }
        }
        return $html;
    }

    /**
     * Return path of the root directory for startup. Also try to create target directory if it doesn't exist
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCurrentPath()
    {
        if (!$this->_currentPath) {
            $currentPath = $this->getStorageRoot();
            $path = $this->_getRequest()->getParam($this->getTreeNodeName());
            if ($path) {
                $path = $this->convertIdToPath($path);
                if ($this->_directory->isDirectory($this->_directory->getRelativePath($path))) {
                    $currentPath = $path;
                }
            }

            $currentTreePath = $this->_getRequest()->getParam('current_tree_path');
            if ($currentTreePath) {
                $currentTreePath = $this->convertIdToPath($currentTreePath);
                $this->createSubDirIfNotExist($currentTreePath);
            }

            $this->_currentPath = $currentPath;
        }

        return $this->_currentPath;
    }

    /**
     * Create subdirectory if doesn't exist
     *
     * @param string $absPath Path of subdirectory to create
     * @throws LocalizedException
     */
    private function createSubDirIfNotExist(string $absPath)
    {
        $relPath = $this->_directory->getRelativePath($absPath);
        if (!$this->_directory->isExist($relPath)) {
            try {
                $this->_directory->create($relPath);
            } catch (FileSystemException $e) {
                $message = __(
                    'Can\'t create %1 as subdirectory of %2, you might have some permission issue.',
                    $relPath,
                    $this->_directory->getAbsolutePath()
                );
                throw new LocalizedException($message);
            }
        }
    }

    /**
     * Return URL based on current selected directory or root directory for startup
     *
     * @return string
     */
    public function getCurrentUrl()
    {
        if (!$this->_currentUrl) {
            $path = $this->getCurrentPath();
            $mediaUrl = $this->_storeManager->getStore(
                $this->_storeId
            )->getBaseUrl(
                UrlInterface::URL_TYPE_MEDIA
            );
            $this->_currentUrl = rtrim($mediaUrl . $this->_directory->getRelativePath($path), '/') . '/';
        }
        return $this->_currentUrl;
    }

    /**
     * Encode string to valid HTML id element, based on base64 encoding
     *
     * @param string $string
     * @return string
     */
    public function idEncode($string)
    {
        return $string === null ? '' : strtr(base64_encode($string), '+/=', ':_-');
    }

    /**
     * Revert operation to idEncode
     *
     * @param string $string
     * @return string
     */
    public function idDecode($string)
    {
        $string = $string === null ? '' : strtr($string, ':_-', '+/=');

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return base64_decode($string);
    }

    /**
     * Reduce filename by replacing some characters with dots
     *
     * @param string $filename
     * @param int $maxLength Maximum filename
     * @return string Truncated filename
     */
    public function getShortFilename($filename, $maxLength = 20)
    {
        if (strlen((string)$filename) <= $maxLength) {
            return $filename;
        }
        return substr($filename, 0, $maxLength) . '...';
    }

    /**
     * Set user-traversable image directory subpath relative to media directory and relative to nested storage root
     *
     * @param string $subpath
     * @return void
     */
    public function setImageDirectorySubpath($subpath)
    {
        $this->imageDirectorySubpath = $subpath;
    }
}
