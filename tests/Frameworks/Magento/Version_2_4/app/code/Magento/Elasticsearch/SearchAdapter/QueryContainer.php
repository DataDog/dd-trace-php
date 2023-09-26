<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Elasticsearch\SearchAdapter;

/**
 * The purpose of this class to be a container for the array with ElasticSearch query.
 * @codeCoverageIgnore
 */
class QueryContainer
{
    /**
     * @var array
     */
    private $query;

    /**
     * @param array $query
     */
    public function __construct(array $query)
    {
        $this->query = $query;
    }

    /**
     * Returns a query
     *
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }
}
