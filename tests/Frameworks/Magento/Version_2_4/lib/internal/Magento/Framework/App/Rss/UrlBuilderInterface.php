<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Rss;

/**
 * Interface UrlBuilderInterface
 *
 * @api
 */
interface UrlBuilderInterface
{
    /**
     * @param array $queryParams
     * @return mixed
     */
    public function getUrl(array $queryParams = []);
}
