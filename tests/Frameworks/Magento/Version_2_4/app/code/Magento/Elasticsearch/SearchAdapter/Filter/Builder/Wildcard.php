<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\SearchAdapter\Filter\Builder;

use Magento\Framework\Search\Request\Filter\Wildcard as WildcardFilterRequest;
use Magento\Framework\Search\Request\FilterInterface as RequestFilterInterface;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;

class Wildcard implements FilterInterface
{
    /**
     * @var FieldMapperInterface
     */
    protected $fieldMapper;

    /**
     * @param FieldMapperInterface $fieldMapper
     */
    public function __construct(FieldMapperInterface $fieldMapper)
    {
        $this->fieldMapper = $fieldMapper;
    }

    /**
     * @param RequestFilterInterface|WildcardFilterRequest $filter
     * @return array
     */
    public function buildFilter(RequestFilterInterface $filter)
    {
        $fieldName = $this->fieldMapper->getFieldName($filter->getField());
        return [
            [
                'wildcard' => [
                    $fieldName => '*' . $filter->getValue() . '*',
                ],
            ]
        ];
    }
}
