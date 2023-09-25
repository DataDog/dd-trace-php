<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Config\Converter;

use Magento\Ui\Config\Converter;
use Magento\Ui\Config\ConverterInterface;
use Magento\Framework\ObjectManager\Config\Reader\Dom;
use Magento\Ui\Config\ConverterUtils;

/**
 * Converter for 'settings/actions' configuration settings
 */
class Actions implements ConverterInterface
{
    /**
     * @var ConverterInterface
     */
    private $converter;

    /**
     * @var ConverterUtils
     */
    private $converterUtils;

    /**
     * @param ConverterInterface $converter
     * @param ConverterUtils $converterUtils
     */
    public function __construct(ConverterInterface $converter, ConverterUtils $converterUtils)
    {
        $this->converter = $converter;
        $this->converterUtils = $converterUtils;
    }

    /**
     * @inheritdoc
     */
    public function convert(\DOMNode $node, array $data = [])
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return [];
        }

        return $this->toArray($node);
    }

    /**
     * Convert nodes and child nodes to array
     *
     * @param \DOMNode $node
     * @return array
     */
    private function toArray(\DOMNode $node)
    {
        if ($node->localName == 'url') {
            $urlResult = $this->converter->convert($node, ['type' => 'url']);
            return $urlResult ?: [];
        }

        $result = [];
        $result[Converter::NAME_ATTRIBUTE_KEY] = $this->converterUtils->getComponentName($node);

        if ($this->hasChildElements($node)) {
            $result[Dom::TYPE_ATTRIBUTE] = 'array';
            /** @var \DOMNode $childNode */
            foreach ($node->childNodes as $childNode) {
                if ($childNode->nodeType === XML_ELEMENT_NODE) {
                    $result['item'][$this->converterUtils->getComponentName($childNode)] = $this->toArray($childNode);
                }
            }
        } else {
            if ($node->nodeType == XML_ELEMENT_NODE) {
                $childResult = [];
                $attributesResult = [];
                $childResult[Converter::NAME_ATTRIBUTE_KEY] = $this->converterUtils->getComponentName($node);
                $childResult[Dom::TYPE_ATTRIBUTE] = 'string';
                if ($node->hasAttributes()) {
                    $attributesResult = $this->processAttributes($node);
                }

                $result = array_merge(['value' => trim($node->nodeValue)], $childResult, $attributesResult);
            }
        }

        return $result;
    }

    /**
     * Check is DOMNode has child DOMElements
     *
     * @param \DOMNode $node
     * @return bool
     */
    private function hasChildElements(\DOMNode $node)
    {
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                if ($childNode->nodeType == XML_ELEMENT_NODE) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Collect node attributes
     *
     * @param \DOMNode $node
     * @return array
     */
    private function processAttributes(\DOMNode $node)
    {
        $attributes = [];
        $childResult = [];
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->value;
        }

        if (isset($attributes['class'])) {
            $childResult[Dom::TYPE_ATTRIBUTE] = 'object';
            $childResult['value'] = $attributes['class'];
            unset($attributes['class']);
        }

        return array_merge($attributes, $childResult);
    }
}
