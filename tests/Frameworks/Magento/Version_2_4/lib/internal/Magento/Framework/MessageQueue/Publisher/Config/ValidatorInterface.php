<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\MessageQueue\Publisher\Config;

/**
 * Publisher config data validator.
 * @api
 */
interface ValidatorInterface
{
    /**
     * Validate publisher config data.
     *
     * @param array $configData
     * @throws \LogicException
     * @return void
     */
    public function validate($configData);
}
