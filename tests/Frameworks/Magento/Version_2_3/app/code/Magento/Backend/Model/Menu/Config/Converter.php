<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Model\Menu\Config;

/**
 * Class Converter converts xml to appropriate array
 * @api
 * @since 100.0.2
 */
class Converter implements \Magento\Framework\Config\ConverterInterface
{
    /**
     * Converts xml to appropriate array
     *
     * @param mixed $dom
     * @return array
     */
    public function convert($dom)
    {
        $extractedData = [];

        $attributeNamesList = [
            'id',
            'title',
            'toolTip',
            'module',
            'sortOrder',
            'action',
            'parent',
            'resource',
            'dependsOnModule',
            'dependsOnConfig',
            'target'
        ];
        $xpath = new \DOMXPath($dom);
        $nodeList = $xpath->query('/config/menu/*');
        for ($i = 0; $i < $nodeList->length; $i++) {
            $item = [];
            $node = $nodeList->item($i);
            $item['type'] = $node->nodeName;
            foreach ($attributeNamesList as $name) {
                if ($node->hasAttribute($name)) {
                    $item[$name] = $node->getAttribute($name);
                }
            }
            $extractedData[] = $item;
        }
        return $extractedData;
    }
}
