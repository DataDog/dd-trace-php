<?php
/**
 * Module declaration xml converter. Converts declaration DOM Document to internal array representation.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Module\Declaration\Converter;

class Dom implements \Magento\Framework\Config\ConverterInterface
{
    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function convert($source)
    {
        $modules = [];
        $xpath = new \DOMXPath($source);
        /** @var $moduleNode \DOMNode */
        foreach ($xpath->query('/config/module') as $moduleNode) {
            $moduleData = [];
            $moduleAttributes = $moduleNode->attributes;
            $nameNode = $moduleAttributes->getNamedItem('name');
            if ($nameNode === null) {
                throw new \Exception('Attribute "name" is required for module node.');
            }
            $moduleData['name'] = $nameNode->nodeValue;
            $versionNode = $moduleAttributes->getNamedItem('setup_version');
            $moduleData['setup_version'] = $versionNode ? $versionNode->nodeValue : null;
            $moduleData['sequence'] = [];
            /** @var $childNode \DOMNode */
            foreach ($moduleNode->childNodes as $childNode) {
                switch ($childNode->nodeName) {
                    case 'sequence':
                        $moduleData['sequence'] = $this->_readModules($childNode);
                        break;
                }
            }
            // Use module name as a key in the result array to allow quick access to module configuration
            $modules[$nameNode->nodeValue] = $moduleData;
        }
        return $modules;
    }

    /**
     * Convert module depends node into assoc array
     *
     * @param \DOMNode $node
     * @return array
     * @throws \Exception
     */
    protected function _readModules(\DOMNode $node)
    {
        $result = [];
        /** @var $childNode \DOMNode */
        foreach ($node->childNodes as $childNode) {
            switch ($childNode->nodeName) {
                case 'module':
                    $nameNode = $childNode->attributes->getNamedItem('name');
                    if ($nameNode === null) {
                        throw new \Exception('Attribute "name" is required for module node.');
                    }
                    $result[] = $nameNode->nodeValue;
                    break;
            }
        }
        return $result;
    }
}
