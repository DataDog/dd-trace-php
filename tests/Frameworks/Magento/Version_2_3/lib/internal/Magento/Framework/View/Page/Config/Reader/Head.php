<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Page\Config\Reader;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Config\Structure;

/**
 * Head structure reader is intended for collecting assets, title and metadata
 */
class Head implements Layout\ReaderInterface
{
    /**#@+
     * Supported types
     */
    const TYPE_HEAD = 'head';
    /**#@-*/

    /**#@+
     * Supported head elements
     */
    const HEAD_CSS = 'css';
    const HEAD_SCRIPT = 'script';
    const HEAD_LINK = 'link';
    const HEAD_REMOVE = 'remove';
    const HEAD_TITLE = 'title';
    const HEAD_META = 'meta';
    const HEAD_ATTRIBUTE = 'attribute';
    private const HEAD_FONT = 'font';
    /**#@-*/

    /**
     * @inheritdoc
     */
    public function getSupportedNodes()
    {
        return [self::TYPE_HEAD];
    }

    /**
     * Add asset content type to node by name
     *
     * @param Layout\Element $node
     * @return void
     */
    protected function addContentTypeByNodeName(Layout\Element $node)
    {
        switch ($node->getName()) {
            case self::HEAD_CSS:
                $node->addAttribute('content_type', 'css');
                break;
            case self::HEAD_SCRIPT:
                $node->addAttribute('content_type', 'js');
                break;
            case self::HEAD_FONT:
                $node->addAttribute('content_type', 'font');
                break;
        }
    }

    /**
     * Read children elements structure and fill scheduled structure
     *
     * @param Layout\Reader\Context $readerContext
     * @param Layout\Element $headElement
     * @return $this|Layout\ReaderInterface
     */
    public function interpret(
        Layout\Reader\Context $readerContext,
        Layout\Element $headElement
    ) {
        $pageConfigStructure = $readerContext->getPageConfigStructure();

        $orderedNodes = [];

        foreach ($headElement as $node) {
            $nodeOrder = $node->getAttribute('order') ?: 0;
            $orderedNodes[$nodeOrder][] = $node;
        }

        ksort($orderedNodes);
        foreach ($orderedNodes as $nodes) {
            /** @var \Magento\Framework\View\Layout\Element $node */
            foreach ($nodes as $node) {
                $this->processNode($node, $pageConfigStructure);
            }
        }
        return $this;
    }

    /**
     * Get all attributes for current dom element
     *
     * @param \Magento\Framework\View\Layout\Element $element
     * @return array
     */
    protected function getAttributes($element)
    {
        $attributes = [];
        foreach ($element->attributes() as $attrName => $attrValue) {
            $attributes[$attrName] = (string)$attrValue;
        }
        return $attributes;
    }

    /**
     * Set metadata
     *
     * @param \Magento\Framework\View\Page\Config\Structure $pageConfigStructure
     * @param \Magento\Framework\View\Layout\Element $node
     * @return void
     */
    private function setMetadata($pageConfigStructure, $node)
    {
        if (!$node->getAttribute('name') && $node->getAttribute('property')) {
            $metadataName = $node->getAttribute('property');
        } else {
            $metadataName = $node->getAttribute('name');
        }

        $pageConfigStructure->setMetadata($metadataName, $node->getAttribute('content'));
    }

    /**
     * Process given node based on it's name.
     *
     * @param Layout\Element $node
     * @param Structure $pageConfigStructure
     * @return void
     */
    private function processNode(Layout\Element $node, Structure $pageConfigStructure)
    {
        switch ($node->getName()) {
            case self::HEAD_CSS:
            case self::HEAD_SCRIPT:
            case self::HEAD_LINK:
            case self::HEAD_FONT:
                $this->addContentTypeByNodeName($node);
                $pageConfigStructure->addAssets($node->getAttribute('src'), $this->getAttributes($node));
                break;

            case self::HEAD_REMOVE:
                $pageConfigStructure->removeAssets($node->getAttribute('src'));
                break;

            case self::HEAD_TITLE:
                $pageConfigStructure->setTitle(new \Magento\Framework\Phrase($node));
                break;

            case self::HEAD_META:
                $this->setMetadata($pageConfigStructure, $node);
                break;

            case self::HEAD_ATTRIBUTE:
                $pageConfigStructure->setElementAttribute(
                    PageConfig::ELEMENT_TYPE_HEAD,
                    $node->getAttribute('name'),
                    $node->getAttribute('value')
                );
                break;

            default:
                break;
        }
    }
}
