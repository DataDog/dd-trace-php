<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Search\Request;

use Magento\Framework\Exception\StateException;
use Magento\Framework\Search\Request\Aggregation\StatusInterface as AggregationStatus;
use Magento\Framework\Phrase;

/**
 * @api
 * @since 100.0.2
 */
class Cleaner
{
    /**
     * @var array
     */
    private $requestData;

    /**
     * @var array
     */
    private $mappedQueries;

    /**
     * @var array
     */
    private $mappedFilters;

    /**
     * @var AggregationStatus
     */
    private $aggregationStatus;

    /**
     * Cleaner constructor
     *
     * @param AggregationStatus $aggregationStatus
     */
    public function __construct(AggregationStatus $aggregationStatus)
    {
        $this->aggregationStatus = $aggregationStatus;
    }

    /**
     * Clean not binder queries and filters
     *
     * @param array $requestData
     * @return array
     */
    public function clean(array $requestData)
    {
        $this->clear();
        $this->requestData = $requestData;
        $this->cleanQuery($requestData['query']);
        $this->cleanAggregations();
        $requestData = $this->requestData;
        $this->clear();

        if (empty($requestData['queries']) && empty($requestData['filters'])) {
            throw new EmptyRequestDataException(
                new Phrase("The request query and filters aren't set. Verify the query and filters and try again.")
            );
        }

        return $requestData;
    }

    /**
     * Clear don't bind queries
     *
     * @param string $queryName
     * @return void
     * @throws StateException
     * @throws \Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    private function cleanQuery($queryName)
    {
        if (!isset($this->requestData['queries'][$queryName])) {
            throw new \Exception('Query ' . $queryName . ' does not exist');
        } elseif (in_array($queryName, $this->mappedQueries)) {
            throw new StateException(
                new Phrase('A cycle was found. The "%1" query is already used in the request hierarchy.', [$queryName])
            );
        }
        $this->mappedQueries[] = $queryName;
        $query = $this->requestData['queries'][$queryName];
        switch ($query['type']) {
            case QueryInterface::TYPE_BOOL:
                $queryReference = $this->processQueryReference($query['queryReference']);
                if (empty($queryReference)) {
                    unset($this->requestData['queries'][$queryName]);
                } else {
                    $this->requestData['queries'][$queryName]['queryReference'] = array_values($queryReference);
                }
                break;
            case QueryInterface::TYPE_MATCH:
                if (!array_key_exists('is_bind', $query)) {
                    unset($this->requestData['queries'][$queryName]);
                }
                break;
            case QueryInterface::TYPE_FILTER:
                if (isset($query['queryReference'][0])) {
                    $fQueryName = $query['queryReference'][0]['ref'];
                    $this->cleanQuery($fQueryName);
                    if (!isset($this->requestData['queries'][$fQueryName])) {
                        unset($this->requestData['queries'][$queryName]);
                    }
                } elseif (isset($query['filterReference'][0])) {
                    $filterName = $query['filterReference'][0]['ref'];
                    $this->cleanFilter($filterName);
                    if (!isset($this->requestData['filters'][$filterName])) {
                        unset($this->requestData['queries'][$queryName]);
                    }
                } else {
                    throw new \Exception('Reference is not provided');
                }
                break;
            default:
                throw new \InvalidArgumentException('Invalid query type');
        }
    }

    /**
     * Clean aggregations if we don't need to process them
     *
     * @return void
     */
    private function cleanAggregations()
    {
        if (!$this->aggregationStatus->isEnabled()) {
            $this->requestData['aggregations'] = [];
        } else {
            if (array_key_exists('aggregations', $this->requestData) && is_array($this->requestData['aggregations'])) {
                foreach ($this->requestData['aggregations'] as $aggregationName => $aggregationValue) {
                    switch ($aggregationValue['type']) {
                        case BucketInterface::TYPE_TERM:
                            foreach ($aggregationValue['parameter'] ?? [] as $key => $parameter) {
                                if (is_string($parameter['value'])
                                    && preg_match('/^\$(.+)\$$/si', $parameter['value'])
                                ) {
                                    unset($this->requestData['aggregations'][$aggregationName]['parameter'][$key]);
                                }
                            }
                            break;
                        case BucketInterface::TYPE_DYNAMIC:
                            if (is_string($aggregationValue['method'])
                                && preg_match('/^\$(.+)\$$/si', $aggregationValue['method'])
                            ) {
                                unset($this->requestData['aggregations'][$aggregationName]);
                            }
                    }
                }
            }
        }
    }

    /**
     * Clear don't bind filters
     *
     * @param string $filterName
     * @return void
     * @throws StateException
     * @throws \Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function cleanFilter($filterName)
    {
        if (!isset($this->requestData['filters'][$filterName])) {
            throw new \Exception('Filter ' . $filterName . ' does not exist');
        } elseif (in_array($filterName, $this->mappedFilters)) {
            throw new StateException(
                new Phrase(
                    'A cycle was found. The "%1" filter is already used in the request hierarchy.',
                    [$filterName]
                )
            );
        }
        $this->mappedFilters[] = $filterName;
        $filter = $this->requestData['filters'][$filterName];
        switch ($filter['type']) {
            case FilterInterface::TYPE_WILDCARD:
            case FilterInterface::TYPE_TERM:
                if (!array_key_exists('is_bind', $filter)) {
                    unset($this->requestData['filters'][$filterName]);
                }
                break;
            case FilterInterface::TYPE_RANGE:
                $keys = ['from', 'to'];
                foreach ($keys as $key) {
                    if (isset($filter[$key]) && preg_match('/^\$(.+)\$$/si', $filter[$key])) {
                        unset($this->requestData['filters'][$filterName][$key]);
                    }
                }
                $filterKeys = array_keys($this->requestData['filters'][$filterName]);
                if (count(array_diff($keys, $filterKeys)) == count($keys)) {
                    unset($this->requestData['filters'][$filterName]);
                }
                break;
            case FilterInterface::TYPE_BOOL:
                $filterReference = $this->processFilterReference($filter['filterReference']);
                if (empty($filterReference)) {
                    unset($this->requestData['filters'][$filterName]);
                } else {
                    $this->requestData['filters'][$filterName]['filterReference'] = array_values($filterReference);
                }
                break;
            default:
                throw new \InvalidArgumentException('Invalid filter type');
        }
    }

    /**
     * Aggregate Queries by clause
     *
     * @param array $queryReference
     * @return array
     */
    private function processQueryReference($queryReference)
    {
        foreach ($queryReference as $key => $value) {
            $this->cleanQuery($value['ref']);
            if (!isset($this->requestData['queries'][$value['ref']])) {
                unset($queryReference[$key]);
            }
        }
        return $queryReference;
    }

    /**
     * Aggregate Filters by clause
     *
     * @param array $filterReference
     * @return array
     */
    private function processFilterReference($filterReference)
    {
        foreach ($filterReference as $key => $value) {
            $this->cleanFilter($value['ref']);
            if (!isset($this->requestData['filters'][$value['ref']])) {
                unset($filterReference[$key]);
            }
        }
        return $filterReference;
    }

    /**
     * Clear variables to default status
     *
     * @return void
     */
    private function clear()
    {
        $this->mappedQueries = [];
        $this->mappedFilters = [];
        $this->requestData = [];
    }
}
