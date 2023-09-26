<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Rss;

/**
 * @api
 * @since 100.0.2
 */
interface DataProviderInterface
{
    /**
     * Check if RSS feed allowed
     *
     * @return mixed
     */
    public function isAllowed();

    /**
     * Get RSS feed items
     *
     * @return array
     */
    public function getRssData();

    /**
     * @return string
     */
    public function getCacheKey();

    /**
     * @return int
     */
    public function getCacheLifetime();

    /**
     * Get information about all feeds this Data Provider is responsible for
     *
     * @return array
     */
    public function getFeeds();

    /**
     * @return bool
     */
    public function isAuthRequired();
}
