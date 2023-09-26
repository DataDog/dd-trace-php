<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Gateway\Config;

use Magento\Framework\Exception\NotFoundException;

/**
 * Interface ValueHandlerPoolInterface
 * @package Magento\Payment\Gateway\Config
 * @api
 * @since 100.0.2
 */
interface ValueHandlerPoolInterface
{
    /**
     * Retrieves an appropriate configuration value handler
     *
     * @param string $field
     * @return ValueHandlerInterface
     * @throws NotFoundException
     */
    public function get($field);
}
