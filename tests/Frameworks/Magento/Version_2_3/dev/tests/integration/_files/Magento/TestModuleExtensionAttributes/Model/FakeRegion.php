<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestModuleExtensionAttributes\Model;

use Magento\Framework\Model\AbstractExtensibleModel;
use Magento\TestModuleExtensionAttributes\Api\Data\FakeRegionInterface;

class FakeRegion extends AbstractExtensibleModel implements FakeRegionInterface
{
    /**
     * Get region
     *
     * @return string
     */
    public function getRegion()
    {
        return $this->getData(self::REGION);
    }

    /**
     * Get region code
     *
     * @return string
     */
    public function getRegionCode()
    {
        return $this->getData(self::REGION_CODE);
    }

    /**
     * Get region id
     *
     * @return int
     */
    public function getRegionId()
    {
        return $this->getData(self::REGION_ID);
    }

    /**
     * {@inheritdoc}
     *
     * @return \Magento\TestModuleExtensionAttributes\Api\Data\FakeRegionExtensionInterface|null
     */
    public function getExtensionAttributes()
    {
        return $this->_getExtensionAttributes();
    }

    /**
     * {@inheritdoc}
     *
     * @param \Magento\TestModuleExtensionAttributes\Api\Data\FakeRegionExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(
        \Magento\TestModuleExtensionAttributes\Api\Data\FakeRegionExtensionInterface $extensionAttributes
    ) {
        return $this->_setExtensionAttributes($extensionAttributes);
    }
}
