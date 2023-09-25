<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Api;

/**
 * Interface AttributeSetManagementInterface
 * @api
 * @since 100.0.2
 */
interface AttributeSetManagementInterface
{
    /**
     * Create attribute set from data
     *
     * @param string $entityTypeCode
     * @param \Magento\Eav\Api\Data\AttributeSetInterface $attributeSet
     * @param int $skeletonId
     * @return \Magento\Eav\Api\Data\AttributeSetInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function create(
        $entityTypeCode,
        \Magento\Eav\Api\Data\AttributeSetInterface $attributeSet,
        $skeletonId
    );
}
