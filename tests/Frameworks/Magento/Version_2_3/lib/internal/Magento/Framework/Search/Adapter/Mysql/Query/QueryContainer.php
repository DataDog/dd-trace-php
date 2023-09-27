<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Search\Adapter\Mysql\Query;

use Magento\Framework\DB\Select;
use Magento\Framework\Search\Request\QueryInterface as RequestQueryInterface;

/**
 * MySQL search query container.
 *
 * @deprecated 102.0.0
 * @see \Magento\ElasticSearch
 */
class QueryContainer
{
    const DERIVED_QUERY_PREFIX = 'derived_';

    /**
     * @var array
     */
    private $queries = [];

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Query\MatchContainerFactory
     */
    private $matchContainerFactory;

    /**
     * @param MatchContainerFactory $matchContainerFactory
     */
    public function __construct(MatchContainerFactory $matchContainerFactory)
    {
        $this->matchContainerFactory = $matchContainerFactory;
    }

    /**
     * Add query to select.
     *
     * @param Select $select
     * @param RequestQueryInterface $query
     * @param string $conditionType
     * @return Select
     */
    public function addMatchQuery(
        Select $select,
        RequestQueryInterface $query,
        $conditionType
    ) {
        $container = $this->matchContainerFactory->create(
            [
                'request' => $query,
                'conditionType' => $conditionType,
            ]
        );
        $name = self::DERIVED_QUERY_PREFIX . count($this->queries);
        $this->queries[$name] = $container;
        return $select;
    }

    /**
     * Get queries.
     *
     * @return MatchContainer[]
     */
    public function getMatchQueries()
    {
        return $this->queries;
    }
}
