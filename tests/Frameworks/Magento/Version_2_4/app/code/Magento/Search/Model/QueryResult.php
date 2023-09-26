<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Search\Model;

/**
 * @api
 * @since 100.0.2
 */
class QueryResult
{
    /**
     * @var string
     */
    private $queryText;

    /**
     * @var int
     */
    private $resultsCount;

    /**
     * @param string $queryText
     * @param string $resultsCount
     */
    public function __construct($queryText, $resultsCount)
    {
        $this->queryText = $queryText;
        $this->resultsCount = $resultsCount;
    }

    /**
     * @return string
     */
    public function getQueryText()
    {
        return $this->queryText;
    }

    /**
     * @return int
     */
    public function getResultsCount()
    {
        return $this->resultsCount;
    }
}
