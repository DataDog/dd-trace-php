<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\BundleGraphQl\Model\Cart\BuyRequest;

use Magento\Framework\Stdlib\ArrayManager;
use Magento\QuoteGraphQl\Model\Cart\BuyRequest\BuyRequestDataProviderInterface;

/**
 * Data provider for bundle product buy requests
 */
class BundleDataProvider implements BuyRequestDataProviderInterface
{
    /**
     * @var ArrayManager
     */
    private $arrayManager;

    /**
     * @param ArrayManager $arrayManager
     */
    public function __construct(
        ArrayManager $arrayManager
    ) {
        $this->arrayManager = $arrayManager;
    }

    /**
     * @inheritdoc
     */
    public function execute(array $cartItemData): array
    {
        $bundleOptions = [];
        $bundleInputs = $this->arrayManager->get('bundle_options', $cartItemData) ?? [];
        foreach ($bundleInputs as $bundleInput) {
            $bundleOptions['bundle_option'][$bundleInput['id']] = $bundleInput['value'];
            $bundleOptions['bundle_option_qty'][$bundleInput['id']] = $bundleInput['quantity'];
        }

        return $bundleOptions;
    }
}
