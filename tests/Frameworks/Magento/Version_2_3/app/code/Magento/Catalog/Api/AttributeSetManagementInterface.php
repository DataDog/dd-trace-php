<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Api;

/**
 * @api
 * @since 100.0.2
 */
interface AttributeSetManagementInterface
{
    /**
     * Create attribute set from data
     *
     * @param \Magento\Eav\Api\Data\AttributeSetInterface $attributeSet
     * @param int $skeletonId
     * @return \Magento\Eav\Api\Data\AttributeSetInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function create(\Magento\Eav\Api\Data\AttributeSetInterface $attributeSet, $skeletonId);
}
