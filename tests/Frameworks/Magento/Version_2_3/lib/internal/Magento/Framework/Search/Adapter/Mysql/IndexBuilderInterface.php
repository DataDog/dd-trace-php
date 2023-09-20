<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search\Adapter\Mysql;

use Magento\Framework\DB\Select;
use Magento\Framework\Search\RequestInterface;

/**
 * Build base Query for Index
 *
 * @deprecated 102.0.0
 * @see \Magento\ElasticSearch
 */
interface IndexBuilderInterface
{
    /**
     * Build index query
     *
     * @param RequestInterface $request
     * @return Select
     */
    public function build(RequestInterface $request);
}
