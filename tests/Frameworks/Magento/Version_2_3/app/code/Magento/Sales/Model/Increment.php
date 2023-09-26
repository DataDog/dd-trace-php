<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model;

use Magento\Eav\Model\Config as EavConfig;

/**
 * Class Increment
 * @deprecated 101.0.0
 */
class Increment
{
    /**
     * @var \Magento\Eav\Model\Config
     */
    protected $eavConfig;

    /**
     * @var string
     */
    protected $incrementValue;

    /**
     * @param EavConfig $eavConfig
     */
    public function __construct(
        EavConfig $eavConfig
    ) {
        $this->eavConfig = $eavConfig;
    }

    /**
     * Returns current increment id
     *
     * @return string
     */
    public function getCurrentValue()
    {
        return $this->incrementValue;
    }

    /**
     * Returns new value of increment id
     *
     * @param int $storeId
     * @return string
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getNextValue($storeId)
    {
        $this->incrementValue =
            $this->eavConfig->getEntityType(Order::ENTITY)->fetchNewIncrementId($storeId);
        return $this->incrementValue;
    }
}
