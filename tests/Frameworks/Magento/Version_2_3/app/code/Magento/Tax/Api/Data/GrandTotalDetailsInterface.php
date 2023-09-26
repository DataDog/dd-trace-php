<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Api\Data;

/**
 * Interface GrandTotalDetailsInterface
 * @api
 * @since 100.0.2
 */
interface GrandTotalDetailsInterface
{
    /**
     * Get tax amount value
     *
     * @return float|string
     */
    public function getAmount();

    /**
     * @param string|float $amount
     * @return $this
     */
    public function setAmount($amount);

    /**
     * Applied tax rates info
     *
     * @return \Magento\Tax\Api\Data\GrandTotalRatesInterface[]
     */
    public function getRates();

    /**
     * @param \Magento\Tax\Api\Data\GrandTotalRatesInterface[] $rates
     * @return $this
     */
    public function setRates($rates);

    /**
     * Details group identifier
     *
     * @return int
     */
    public function getGroupId();

    /**
     * @param int $id
     * @return $this
     */
    public function setGroupId($id);
}
