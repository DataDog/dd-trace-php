<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search\Adapter\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * MySQL search condition manager
 *
 * @api
 * @deprecated 102.0.0
 * @see \Magento\ElasticSearch
 * @since 100.0.2
 */
class ConditionManager
{
    const CONDITION_PATTERN_SIMPLE = '%s %s %s';
    const CONDITION_PATTERN_ARRAY = '%s %s (%s)';

    /**
     * @var AdapterInterface
     */
    private $connection;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->connection = $resource->getConnection();
    }

    /**
     * Wrap query with parentheses.
     *
     * @param string $query
     * @return string
     */
    public function wrapBrackets($query)
    {
        return empty($query)
            ? $query
            : '(' . $query . ')';
    }

    /**
     * Combine multiple queries.
     *
     * @param string[] $queries
     * @param string $unionOperator
     * @return string
     */
    public function combineQueries(array $queries, $unionOperator)
    {
        return implode(
            ' ' . $unionOperator . ' ',
            array_filter($queries, 'strlen')
        );
    }

    /**
     * Generate query condition.
     *
     * @param string $field
     * @param string $operator
     * @param mixed $value
     * @return string
     */
    public function generateCondition($field, $operator, $value)
    {
        return sprintf(
            is_array($value) ? self::CONDITION_PATTERN_ARRAY : self::CONDITION_PATTERN_SIMPLE,
            $this->connection->quoteIdentifier($field),
            $operator,
            $this->connection->quote($value)
        );
    }
}
