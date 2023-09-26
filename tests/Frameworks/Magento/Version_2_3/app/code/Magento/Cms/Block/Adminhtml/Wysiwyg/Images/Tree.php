<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Block\Adminhtml\Wysiwyg\Images;

/**
 * Directory tree renderer for Cms Wysiwyg Images
 *
 * @api
 * @since 100.0.2
 */
class Tree extends \Magento\Backend\Block\Template
{
    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * Cms wysiwyg images
     *
     * @var \Magento\Cms\Helper\Wysiwyg\Images
     */
    protected $_cmsWysiwygImages = null;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     * @param \Magento\Framework\Serialize\Serializer\Json|null $serializer
     * @throws \RuntimeException
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages,
        \Magento\Framework\Registry $registry,
        array $data = [],
        \Magento\Framework\Serialize\Serializer\Json $serializer = null
    ) {
        $this->_coreRegistry = $registry;
        $this->_cmsWysiwygImages = $cmsWysiwygImages;
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Serialize\Serializer\Json::class);
        parent::__construct($context, $data);
    }

    /**
     * Json tree builder
     *
     * @return string
     */
    public function getTreeJson()
    {
        $storageRoot = $this->_cmsWysiwygImages->getStorageRoot();
        $collection = $this->_coreRegistry->registry(
            'storage'
        )->getDirsCollection(
            $this->_cmsWysiwygImages->getCurrentPath()
        );
        $jsonArray = [];
        foreach ($collection as $item) {
            $data = [
                'text' => $this->_cmsWysiwygImages->getShortFilename($item->getBasename(), 20),
                'id' => $this->_cmsWysiwygImages->convertPathToId($item->getFilename()),
                'path' => substr($item->getFilename(), strlen($storageRoot)),
                'cls' => 'folder',
            ];

            $hasNestedDirectories = count(glob($item->getFilename() . '/*', GLOB_ONLYDIR)) > 0;

            // if no nested directories inside dir, add 'leaf' state so that jstree hides dropdown arrow next to dir
            if (!$hasNestedDirectories) {
                $data['state'] = 'leaf';
            }

            $jsonArray[] = $data;
        }
        return $this->serializer->serialize($jsonArray);
    }

    /**
     * Json source URL
     *
     * @return string
     */
    public function getTreeLoaderUrl()
    {
        $params = [];

        $currentTreePath = $this->getRequest()->getParam('current_tree_path');

        if (strlen($currentTreePath)) {
            $params['current_tree_path'] = $currentTreePath;
        }

        return $this->getUrl(
            'cms/*/treeJson',
            $params
        );
    }

    /**
     * Root node name of tree
     *
     * @return \Magento\Framework\Phrase
     */
    public function getRootNodeName()
    {
        return __('Storage Root');
    }

    /**
     * Return tree node full path based on current path
     *
     * @return string
     */
    public function getTreeCurrentPath()
    {
        $treePath = ['root'];

        if ($idEncodedPath = $this->getRequest()->getParam('current_tree_path')) {
            $path = $this->_cmsWysiwygImages->idDecode($idEncodedPath);
        } else {
            $path = $this->_coreRegistry->registry('storage')->getSession()->getCurrentPath();
        }

        if (strlen($path)) {
            $path = str_replace($this->_cmsWysiwygImages->getStorageRoot(), '', $path);
            $relative = [];
            foreach (explode('/', $path) as $dirName) {
                if ($dirName) {
                    $relative[] = $dirName;
                    $treePath[] = $this->_cmsWysiwygImages->idEncode(implode('/', $relative));
                }
            }
        }

        return $treePath;
    }

    /**
     * Get tree widget options
     *
     * @return array
     */
    public function getTreeWidgetOptions()
    {
        return [
            "folderTree" => [
                "rootName" => $this->getRootNodeName(),
                "url" => $this->getTreeLoaderUrl(),
                "currentPath" => array_reverse($this->getTreeCurrentPath()),
            ]
        ];
    }
}
