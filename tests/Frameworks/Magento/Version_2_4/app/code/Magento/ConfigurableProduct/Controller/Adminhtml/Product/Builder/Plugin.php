<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Controller\Adminhtml\Product\Builder;

use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Model\Product\Type;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Controller\Adminhtml\Product\Builder as CatalogProductBuilder;
use Magento\Framework\App\RequestInterface;

class Plugin
{
    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable
     */
    protected $configurableType;

    /**
     * @param ProductFactory $productFactory
     * @param Type\Configurable $configurableType
     */
    public function __construct(ProductFactory $productFactory, Type\Configurable $configurableType)
    {
        $this->productFactory = $productFactory;
        $this->configurableType = $configurableType;
    }

    /**
     * Set type and data to configurable product
     *
     * @param CatalogProductBuilder $subject
     * @param Product $product
     * @param RequestInterface $request
     * @return Product
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function afterBuild(CatalogProductBuilder $subject, Product $product, RequestInterface $request)
    {
        if ($request->has('attributes')) {
            $attributes = $request->getParam('attributes');
            if (!empty($attributes)) {
                $product->setTypeId(\Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE);
                $this->configurableType->setUsedProductAttributes($product, $attributes);
            } else {
                $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
            }
        }

        // Required attributes of simple product for configurable creation
        if ($request->getParam('popup') && ($requiredAttributes = $request->getParam('required'))) {
            $requiredAttributes = explode(",", $requiredAttributes);
            foreach ($product->getAttributes() as $attribute) {
                if (in_array($attribute->getId(), $requiredAttributes)) {
                    $attribute->setIsRequired(1);
                }
            }
        }

        if ($request->getParam('popup')
            && $request->getParam('product')
            && !is_array($request->getParam('product'))
            && $request->getParam('id', false) === false
        ) {
            $configProduct = $this->productFactory->create();
            $configProduct->setStoreId(0)
                ->load($request->getParam('product'))
                ->setTypeId($request->getParam('type'));

            $data = [];
            foreach ($configProduct->getTypeInstance()->getSetAttributes($configProduct) as $attribute) {
                /* @var $attribute \Magento\Catalog\Model\ResourceModel\Eav\Attribute */
                if (!$attribute->getIsUnique() &&
                    $attribute->getFrontend()->getInputType() != 'gallery' &&
                    $attribute->getAttributeCode() != 'required_options' &&
                    $attribute->getAttributeCode() != 'has_options' &&
                    $attribute->getAttributeCode() != $configProduct->getIdFieldName()
                ) {
                    $data[$attribute->getAttributeCode()] = $configProduct->getData($attribute->getAttributeCode());
                }
            }
            $product->addData($data);
            $product->setWebsiteIds($configProduct->getWebsiteIds());
        }

        return $product;
    }
}
