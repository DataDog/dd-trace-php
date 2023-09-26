<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Api;

/**
 * Manage children products of configurable product
 *
 * @api
 * @since 100.0.2
 */
interface LinkManagementInterface
{
    /**
     * Get all children for Configurable product
     *
     * @param string $sku
     * @return \Magento\Catalog\Api\Data\ProductInterface[]
     */
    public function getChildren($sku);

    /**
     * @param  string $sku
     * @param  string $childSku
     * @return bool
     */
    public function addChild($sku, $childSku);

    /**
     * Remove configurable product option
     *
     * @param string $sku
     * @param string $childSku
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     * @return bool
     */
    public function removeChild($sku, $childSku);
}
