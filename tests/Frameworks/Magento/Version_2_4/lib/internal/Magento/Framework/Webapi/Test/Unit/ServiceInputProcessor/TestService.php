<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor;

use Magento\Framework\Api\SearchCriteriaInterface;

class TestService
{
    public const DEFAULT_VALUE = 42;
    public const CUSTOM_ATTRIBUTE_CODE = 'customAttr';

    /**
     * @param int $entityId
     * @param string $name
     * @return string[]
     */
    public function simple($entityId, $name)
    {
        return [$entityId, $name];
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\SimpleConstructor $simpleConstructor
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\SimpleConstructor
     */
    public function simpleConstructor(SimpleConstructor $simpleConstructor)
    {
        return $simpleConstructor;
    }

    /**
     * @param int $entityId
     * @return string[]
     */
    public function simpleDefaultValue($entityId = self::DEFAULT_VALUE)
    {
        return [$entityId];
    }

    /**
     * @param int $entityId
     * @return string[]
     */
    public function constructorArguments($entityId = self::DEFAULT_VALUE)
    {
        return [$entityId];
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\Nested $nested
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\Nested
     */
    public function nestedData(Nested $nested)
    {
        return $nested;
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    public function simpleArray(array $ids)
    {
        return $ids;
    }

    /**
     * @param string[] $associativeArray
     * @return string[]
     */
    public function associativeArray(array $associativeArray)
    {
        return $associativeArray;
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\Simple[] $dataObjects
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\Simple[]
     */
    public function dataArray(array $dataObjects)
    {
        return $dataObjects;
    }

    /**
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Framework\Api\SearchCriteriaInterface
     */
    public function search(SearchCriteriaInterface $searchCriteria)
    {
        return $searchCriteria;
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\SimpleArray $arrayData
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\SimpleArray
     */
    public function nestedSimpleArray(SimpleArray $arrayData)
    {
        return $arrayData;
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\AssociativeArray $associativeArrayData
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\AssociativeArray
     */
    public function nestedAssociativeArray(AssociativeArray $associativeArrayData)
    {
        return $associativeArrayData;
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\DataArray $dataObjects
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\DataArray
     */
    public function nestedDataArray(DataArray $dataObjects)
    {
        return $dataObjects;
    }

    /**
     * @param \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\ObjectWithCustomAttributes $param
     * @return \Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor\ObjectWithCustomAttributes
     */
    public function objectWithCustomAttributesMethod(
        ObjectWithCustomAttributes $param
    ) {
        return $param;
    }
}
