<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Console\Command\ConfigSet;

use Magento\Config\Console\Command\ConfigSetCommand;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Allows to process different flows of config:set command.
 *
 * @see ConfigSetCommand
 *
 * @api
 * @since 101.0.0
 */
interface ConfigSetProcessorInterface
{
    /**
     * Processes config:set command.
     *
     * @param string $path The configuration path in format group/section/field_name
     * @param string $value The configuration value
     * @param string $scope The configuration scope (default, website, or store)
     * @param string $scopeCode The scope code
     * @return void
     * @throws CouldNotSaveException An exception on processing error
     * @since 101.0.0
     */
    public function process($path, $value, $scope, $scopeCode);
}
