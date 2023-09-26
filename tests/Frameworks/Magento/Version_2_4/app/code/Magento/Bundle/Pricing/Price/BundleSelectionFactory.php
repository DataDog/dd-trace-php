<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Bundle\Pricing\Price;

use Magento\Catalog\Model\Product;

/**
 * Bundle selection price factory
 * @api
 * @since 100.0.2
 */
class BundleSelectionFactory
{
    /**
     * Default selection class
     */
    const SELECTION_CLASS_DEFAULT = \Magento\Bundle\Pricing\Price\BundleSelectionPrice::class;

    /**
     * Object Manager
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Construct
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create Price object for particular product
     *
     * @param Product $bundleProduct
     * @param Product $selection
     * @param float $quantity
     * @param array $arguments
     * @return BundleSelectionPrice
     */
    public function create(
        Product $bundleProduct,
        Product $selection,
        $quantity,
        array $arguments = []
    ) {
        $quantity = $quantity ? (float)$quantity : 1.;
        $selection->setQty($quantity);

        $arguments['bundleProduct'] = $bundleProduct;
        $arguments['saleableItem'] = $selection;
        $arguments['quantity'] = $quantity;

        return $this->objectManager->create(self::SELECTION_CLASS_DEFAULT, $arguments);
    }
}
