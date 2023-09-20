<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search;

use Magento\Framework\Search\Request\BucketInterface as RequestBucketInterface;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\Request\QueryInterface;

/**
 * Search Request
 *
 * @codeCoverageIgnore
 * @api
 * @since 100.0.2
 */
class Request implements RequestInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $index;

    /**
     * @var RequestBucketInterface[]
     */
    protected $buckets;

    /**
     * Main query which represents the whole query hierarchy
     *
     * @var QueryInterface
     */
    protected $query;

    /**
     * @var int|null
     */
    protected $from;

    /**
     * @var int|null
     */
    protected $size;

    /**
     * @var Dimension[]
     */
    protected $dimensions;

    /**
     * @var array
     */
    private $sort;

    /**
     * @param string $name
     * @param string $indexName
     * @param QueryInterface $query
     * @param int|null $from
     * @param int|null $size
     * @param Dimension[] $dimensions
     * @param RequestBucketInterface[] $buckets
     * @param array $sort
     */
    public function __construct(
        $name,
        $indexName,
        QueryInterface $query,
        $from = null,
        $size = null,
        array $dimensions = [],
        array $buckets = [],
        $sort = []
    ) {
        $this->name = $name;
        $this->index = $indexName;
        $this->query = $query;
        $this->from = $from;
        $this->size = $size;
        $this->buckets = $buckets;
        $this->dimensions = $dimensions;
        $this->sort = $sort;
    }

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * @inheritdoc
     */
    public function getDimensions()
    {
        return $this->dimensions;
    }

    /**
     * @inheritdoc
     */
    public function getAggregation()
    {
        return $this->buckets;
    }

    /**
     * @inheritdoc
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @inheritdoc
     */
    public function getFrom()
    {
        return $this->from;
    }

    /**
     * @inheritdoc
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Temporary solution for an existing interface of a fulltext search request in Backward compatibility purposes.
     * Don't use this function.
     * It must be move to different interface.
     * Scope to split Search request interface on two different 'Search' and 'Fulltext Search' contains in MC-16461.
     *
     * @deprecated 102.0.2
     * @return array
     * @since 102.0.2
     */
    public function getSort()
    {
        return $this->sort;
    }
}
