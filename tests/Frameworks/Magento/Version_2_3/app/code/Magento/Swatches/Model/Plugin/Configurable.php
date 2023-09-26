<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Swatches\Model\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProductType;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\Collection;

class Configurable
{
    /**
     * @var \Magento\Eav\Model\Config|\Magento\Swatches\Model\SwatchFactory
     */
    private $eavConfig;

    /**
     * @var \Magento\Swatches\Helper\Data
     */
    private $swatchHelper;

    /**
     * @param \Magento\Swatches\Model\SwatchFactory $eavConfig
     * @param \Magento\Swatches\Helper\Data $swatchHelper
     */
    public function __construct(
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Swatches\Helper\Data $swatchHelper
    ) {
        $this->eavConfig = $eavConfig;
        $this->swatchHelper = $swatchHelper;
    }

    /**
     * Add swatch attributes to Configurable Products Collection
     *
     * @param ConfigurableProductType $subject
     * @param Collection $result
     * @param ProductInterface $product
     * @return Collection
     */
    public function afterGetUsedProductCollection(
        ConfigurableProductType $subject,
        Collection $result,
        ProductInterface $product
    ) {
        $swatchAttributes = ['image'];
        foreach ($subject->getUsedProductAttributes($product) as $code => $attribute) {
            if ($attribute->getData('additional_data')
                && (
                    $this->swatchHelper->isVisualSwatch($attribute) || $this->swatchHelper->isTextSwatch($attribute)
                )
            ) {
                $swatchAttributes[] = $code;
            }
        }
        $result->addAttributeToSelect($swatchAttributes);
        return $result;
    }
}
