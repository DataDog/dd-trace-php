<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App;

/**
 * Interface \Magento\Framework\App\ScopeTreeProviderInterface
 *
 */
interface ScopeTreeProviderInterface
{
    /**
     * Return tree of scopes like:
     * [
     *      'scope' => 'default',
     *      'scope_id' => null,
     *      'scopes' => [
     *          [
     *              'scope' => 'website',
     *              'scope_id' => 1,
     *              'scopes' => [
     *                  ...
     *              ],
     *          ],
     *          ...
     *      ],
     * ]
     *
     * @return array
     */
    public function get();
}
