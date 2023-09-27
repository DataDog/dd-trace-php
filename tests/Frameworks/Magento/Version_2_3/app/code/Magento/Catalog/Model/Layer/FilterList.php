<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Layer;

/**
 * Layer navigation filters
 */
class FilterList
{
    const CATEGORY_FILTER   = 'category';
    const ATTRIBUTE_FILTER  = 'attribute';
    const PRICE_FILTER      = 'price';
    const DECIMAL_FILTER    = 'decimal';

    /**
     * Filter factory
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var FilterableAttributeListInterface
     */
    protected $filterableAttributes;

    /**
     * @var string[]
     */
    protected $filterTypes = [
        self::CATEGORY_FILTER  => \Magento\Catalog\Model\Layer\Filter\Category::class,
        self::ATTRIBUTE_FILTER => \Magento\Catalog\Model\Layer\Filter\Attribute::class,
        self::PRICE_FILTER     => \Magento\Catalog\Model\Layer\Filter\Price::class,
        self::DECIMAL_FILTER   => \Magento\Catalog\Model\Layer\Filter\Decimal::class,
    ];

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\AbstractFilter[]
     */
    protected $filters = [];

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param FilterableAttributeListInterface $filterableAttributes
     * @param array $filters
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        FilterableAttributeListInterface $filterableAttributes,
        array $filters = []
    ) {
        $this->objectManager = $objectManager;
        $this->filterableAttributes = $filterableAttributes;

        /** Override default filter type models */
        $this->filterTypes = array_merge($this->filterTypes, $filters);
    }

    /**
     * Retrieve list of filters
     *
     * @param \Magento\Catalog\Model\Layer $layer
     * @return array|Filter\AbstractFilter[]
     */
    public function getFilters(\Magento\Catalog\Model\Layer $layer)
    {
        if (!count($this->filters)) {
            $this->filters = [
                $this->objectManager->create($this->filterTypes[self::CATEGORY_FILTER], ['layer' => $layer]),
            ];
            foreach ($this->filterableAttributes->getList() as $attribute) {
                $this->filters[] = $this->createAttributeFilter($attribute, $layer);
            }
        }
        return $this->filters;
    }

    /**
     * Create filter
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param \Magento\Catalog\Model\Layer $layer
     * @return \Magento\Catalog\Model\Layer\Filter\AbstractFilter
     */
    protected function createAttributeFilter(
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute,
        \Magento\Catalog\Model\Layer $layer
    ) {
        $filterClassName = $this->getAttributeFilterClass($attribute);

        $filter = $this->objectManager->create(
            $filterClassName,
            ['data' => ['attribute_model' => $attribute], 'layer' => $layer]
        );
        return $filter;
    }

    /**
     * Get Attribute Filter Class Name
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @return string
     */
    protected function getAttributeFilterClass(\Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute)
    {
        $filterClassName = $this->filterTypes[self::ATTRIBUTE_FILTER];

        if ($attribute->getAttributeCode() == 'price') {
            $filterClassName = $this->filterTypes[self::PRICE_FILTER];
        } elseif ($attribute->getBackendType() == 'decimal') {
            $filterClassName = $this->filterTypes[self::DECIMAL_FILTER];
        }

        return $filterClassName;
    }
}
