<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product\Attribute\Backend\Media\ImageEntryConverter;
use Magento\Framework\View\Xsd\Media\TypeDataExtractorInterface;

/**
 * Image extractor from xml configuration
 */
class ImageExtractor implements TypeDataExtractorInterface
{
    /**
     * Extract configuration data of images from the DOM structure
     *
     * @param \DOMElement $mediaNode
     * @param string $mediaParentTag
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function process(\DOMElement $mediaNode, $mediaParentTag)
    {
        $result = [];
        /** @var \DOMElement $node */
        $moduleNameImage = $mediaNode->getAttribute('module');
        foreach ($mediaNode->getElementsByTagName(ImageEntryConverter::MEDIA_TYPE_CODE) as $node) {
            $imageId = $node->getAttribute('id');
            $result[$mediaParentTag][$moduleNameImage][Image::MEDIA_TYPE_CONFIG_NODE][$imageId]['type']
                = $node->getAttribute('type');
            foreach ($node->childNodes as $attribute) {
                if ($attribute->nodeType != XML_ELEMENT_NODE) {
                    continue;
                }
                $attributeTagName = $attribute->tagName;
                if ((bool)$attribute->getAttribute('xsi:nil') !== true) {
                    if ($attributeTagName === 'background') {
                        $nodeValue = $this->processImageBackground($attribute->nodeValue);
                    } elseif ($attributeTagName === 'width' || $attributeTagName === 'height') {
                        $nodeValue = (int) $attribute->nodeValue;
                    } elseif ($attributeTagName === 'constrain'
                        || $attributeTagName === 'aspect_ratio'
                        || $attributeTagName === 'frame'
                        || $attributeTagName === 'transparency'
                    ) {
                        $nodeValue = in_array($attribute->nodeValue, [true, 1, 'true', '1'], true) ?? false;
                    } else {
                        $nodeValue = $attribute->nodeValue;
                    }
                } else {
                    $nodeValue = null;
                }
                $result[$mediaParentTag][$moduleNameImage][Image::MEDIA_TYPE_CONFIG_NODE][$imageId][$attribute->tagName]
                    = $nodeValue;
            }
        }

        return $result;
    }

    /**
     * Convert rgb background string into array
     *
     * @param string $backgroundString
     * @return int[]
     */
    private function processImageBackground($backgroundString)
    {
        $pattern = '#\[(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\]#';
        $backgroundArray = [];
        if (preg_match($pattern, $backgroundString, $backgroundArray)) {
            array_shift($backgroundArray);
            $backgroundArray = array_map('intval', $backgroundArray);
        }
        return $backgroundArray;
    }
}
