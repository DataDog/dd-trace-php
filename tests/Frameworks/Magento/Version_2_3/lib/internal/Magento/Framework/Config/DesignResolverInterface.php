<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Config;

/**
 * Interface DesignResolverInterface
 * @api
 * @since 100.1.0
 */
interface DesignResolverInterface extends FileResolverInterface
{
    /**
     * Retrieve parent configs
     *
     * @param string $filename
     * @param string $scope
     * @return array
     * @since 100.1.0
     */
    public function getParents($filename, $scope);
}
