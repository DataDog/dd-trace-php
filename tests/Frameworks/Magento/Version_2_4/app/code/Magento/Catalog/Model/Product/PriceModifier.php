<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Model\Product;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Product form price modifier
 */
class PriceModifier
{
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     */
    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
    ) {
        $this->productRepository = $productRepository;
    }

    /**
     * Remove tier price
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param int|string $customerGroupId
     * @param int $qty
     * @param int $websiteId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function removeTierPrice(\Magento\Catalog\Model\Product $product, $customerGroupId, $qty, $websiteId)
    {
        $prices = $product->getData('tier_price');
        // verify if price exist
        if ($prices === null) {
            throw new NoSuchEntityException(__('Tier price is unavailable for this product.'));
        }
        $tierPricesQty = count($prices);

        foreach ($prices as $key => $tierPrice) {
            if ($customerGroupId == 'all' && $tierPrice['price_qty'] == $qty
                && $tierPrice['all_groups'] == 1 && (int) $tierPrice['website_id'] === (int) $websiteId
            ) {
                unset($prices[$key]);
            } elseif ($tierPrice['price_qty'] == $qty && $tierPrice['cust_group'] == $customerGroupId
                && (int) $tierPrice['website_id'] === (int) $websiteId
            ) {
                unset($prices[$key]);
            }
        }

        if ($tierPricesQty == count($prices)) {
            throw new NoSuchEntityException(
                __(
                    'Product hasn\'t group price with such data: customerGroupId = \'%1\''
                    . ', website = %2, qty = %3',
                    [$customerGroupId, $websiteId, $qty]
                )
            );
        }
        $product->setData('tier_price', $prices);
        try {
            $this->productRepository->save($product);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__('The tier_price data is invalid. Verify the data and try again.'));
        }
    }
}
