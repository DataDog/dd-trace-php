<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Gateway\Http;

/**
 * Interface TransferFactoryInterface
 * @package Magento\Payment\Gateway\Http
 * @api
 * @since 100.0.2
 */
interface TransferFactoryInterface
{
    /**
     * Builds gateway transfer object
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request);
}
