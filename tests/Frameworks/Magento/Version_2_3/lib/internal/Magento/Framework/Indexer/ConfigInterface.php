<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Indexer;

/**
 * Indexer(s) configuration
 *
 * @api
 * @since 100.0.2
 */
interface ConfigInterface
{
    /**
     * Get indexers list
     *
     * @return array[]
     */
    public function getIndexers();

    /**
     * Get indexer by ID
     *
     * @param string $indexerId
     * @return array
     */
    public function getIndexer($indexerId);
}
