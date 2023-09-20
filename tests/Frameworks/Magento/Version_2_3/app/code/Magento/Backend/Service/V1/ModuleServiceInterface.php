<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Service\V1;

/**
 * Interface for module service.
 * @api
 * @since 100.0.2
 */
interface ModuleServiceInterface
{
    /**
     * Returns an array of enabled modules
     *
     * @return string[]
     */
    public function getModules();
}
