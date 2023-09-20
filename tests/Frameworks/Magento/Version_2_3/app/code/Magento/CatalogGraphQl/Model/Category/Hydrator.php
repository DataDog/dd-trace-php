<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogGraphQl\Model\Category;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\CustomAttributesFlattener;
use Magento\Framework\Reflection\DataObjectProcessor;

/**
 * Hydrate GraphQL category structure with model data.
 */
class Hydrator
{
    /**
     * @var CustomAttributesFlattener
     */
    private $flattener;

    /**
     * @var DataObjectProcessor
     */
    private $dataObjectProcessor;

    /**
     * @param CustomAttributesFlattener $flattener
     * @param DataObjectProcessor $dataObjectProcessor
     */
    public function __construct(
        CustomAttributesFlattener $flattener,
        DataObjectProcessor $dataObjectProcessor
    ) {
        $this->flattener = $flattener;
        $this->dataObjectProcessor = $dataObjectProcessor;
    }

    /**
     * Hydrate and flatten category object to flat array
     *
     * @param Category $category
     * @param bool $basicFieldsOnly Set to false to avoid expensive hydration, used for performance optimization
     * @return array
     */
    public function hydrateCategory(Category $category, $basicFieldsOnly = false) : array
    {
        if ($basicFieldsOnly) {
            $categoryData = $category->getData();
        } else {
            $categoryData = $this->dataObjectProcessor->buildOutputDataArray($category, CategoryInterface::class);
        }
        $categoryData['id'] = $category->getId();
        $categoryData['children'] = [];
        $categoryData['available_sort_by'] = $category->getAvailableSortBy();
        $categoryData['model'] = $category;
        return $this->flattener->flatten($categoryData);
    }
}
