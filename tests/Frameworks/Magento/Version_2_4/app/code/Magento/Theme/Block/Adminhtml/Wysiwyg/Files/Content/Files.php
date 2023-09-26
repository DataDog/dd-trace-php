<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Block\Adminhtml\Wysiwyg\Files\Content;

/**
 * Files files block
 *
 * @api
 * @method
 *  \Magento\Theme\Block\Adminhtml\Wysiwyg\Files\Content\Files setStorage(\Magento\Theme\Model\Wysiwyg\Storage $storage)
 * @method \Magento\Theme\Model\Wysiwyg\Storage getStorage()
 * @since 100.0.2
 */
class Files extends \Magento\Backend\Block\Template
{
    /**
     * Files list
     *
     * @var null|array
     */
    protected $_files;

    /**
     * Get files
     *
     * @return array
     */
    public function getFiles()
    {
        if (null === $this->_files && $this->getStorage()) {
            $this->_files = $this->getStorage()->getFilesCollection();
        }

        return $this->_files;
    }

    /**
     * Get files count
     *
     * @return int
     */
    public function getFilesCount()
    {
        return count($this->getFiles());
    }
}
