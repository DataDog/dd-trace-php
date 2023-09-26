<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Ui\DataProvider\Product\Form\Modifier;

use Magento\Bundle\Model\Product\Price;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Catalog\Model\Locator\LocatorInterface;

/**
 * Customize Price field
 */
class BundlePrice extends AbstractModifier
{
    const CODE_PRICE_TYPE = 'price_type';
    const CODE_TAX_CLASS_ID = 'tax_class_id';

    /**
     * @var ArrayManager
     */
    protected $arrayManager;

    /**
     * @var LocatorInterface
     */
    protected $locator;

    /**
     * @param LocatorInterface $locator
     * @param ArrayManager $arrayManager
     */
    public function __construct(
        LocatorInterface $locator,
        ArrayManager $arrayManager
    ) {
        $this->locator = $locator;
        $this->arrayManager = $arrayManager;
    }

    /**
     * @inheritdoc
     */
    public function modifyMeta(array $meta)
    {
        $meta = $this->arrayManager->merge(
            $this->arrayManager->findPath(static::CODE_PRICE_TYPE, $meta, null, 'children') . static::META_CONFIG_PATH,
            $meta,
            [
                'disabled' => (bool)$this->locator->getProduct()->getId(),
                'valueMap' => [
                    'false' => '1',
                    'true' => '0'
                ],
                'validation' => [
                    'required-entry' => false
                ]
            ]
        );

        $meta = $this->arrayManager->merge(
            $this->arrayManager->findPath(
                ProductAttributeInterface::CODE_PRICE,
                $meta,
                self::DEFAULT_GENERAL_PANEL . '/children',
                'children'
            ) . static::META_CONFIG_PATH,
            $meta,
            [
                'imports' => [
                    'disabled' => 'ns = ${ $.ns }, index = ' . static::CODE_PRICE_TYPE . ':checked',
                    '__disableTmpl' => ['disabled' => false],
                ]
            ]
        );

        $meta = $this->arrayManager->merge(
            $this->arrayManager->findPath(
                static::CODE_TAX_CLASS_ID,
                $meta,
                null,
                'children'
            ) . static::META_CONFIG_PATH,
            $meta,
            [
                'imports' => [
                    'disabled' => 'ns = ${ $.ns }, index = ' . static::CODE_PRICE_TYPE . ':checked',
                    '__disableTmpl' => ['disabled' => false],
                ]
            ]
        );
        if ($this->locator->getProduct()->getPriceType() == Price::PRICE_TYPE_DYNAMIC) {
            $meta = $this->arrayManager->merge(
                $this->arrayManager->findPath(
                    static::CODE_TAX_CLASS_ID,
                    $meta,
                    null,
                    'children'
                ) . static::META_CONFIG_PATH,
                $meta,
                [
                    'service' => [
                        'template' => ''
                    ]
                ]
            );
        }
        return $meta;
    }

    /**
     * @inheritdoc
     */
    public function modifyData(array $data)
    {
        return $data;
    }
}
