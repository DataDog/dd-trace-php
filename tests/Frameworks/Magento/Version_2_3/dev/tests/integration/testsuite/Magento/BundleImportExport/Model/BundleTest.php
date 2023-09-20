<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\BundleImportExport\Model;

use Magento\CatalogImportExport\Model\AbstractProductExportImportTestCase;

class BundleTest extends AbstractProductExportImportTestCase
{
    /**
     * @return array
     */
    public function exportImportDataProvider(): array
    {
        return [
            // @todo uncomment after MAGETWO-49677 resolved
            /*
            'bundle-product' => [
                [
                    'Magento/Bundle/_files/product.php'
                ],
                [
                    'bundle-product',
                ]
            ],
            */
            'bundle-product-multi-options' => [
                [
                    'Magento/Bundle/_files/product_with_multiple_options.php'
                ],
                [
                    'bundle-product',
                ]
            ],
            // @todo uncomment after MAGETWO-49677 resolved
            /*
            'bundle-product-tier-pricing' => [
                [
                    'Magento/Bundle/_files/product_with_tier_pricing.php'
                ],
                [
                    'bundle-product',
                ]
            ]
            */
        ];
    }

    /**
     * @inheritdoc
     */
    protected function assertEqualsSpecificAttributes(
        \Magento\Catalog\Model\Product $expectedProduct,
        \Magento\Catalog\Model\Product $actualProduct
    ): void {
        $expectedBundleProductOptions = $expectedProduct->getExtensionAttributes()->getBundleProductOptions();
        $actualBundleProductOptions = $actualProduct->getExtensionAttributes()->getBundleProductOptions();

        $this->assertEquals(count($expectedBundleProductOptions), count($actualBundleProductOptions));

        $expectedBundleProductOptionsToCompare = [];
        foreach ($expectedBundleProductOptions as $expectedBundleProductOption) {
            $expectedBundleProductOptionsToCompare[$expectedBundleProductOption->getTitle()]['type']
                = $expectedBundleProductOption->getType();
            foreach ($expectedBundleProductOption->getProductLinks() as $productLink) {
                $expectedBundleProductOptionsToCompare[$expectedBundleProductOption->getTitle()]['product_links'][]
                    = $productLink->getSku();
            }
        }

        $actualBundleProductOptionsToCompare = [];
        foreach ($actualBundleProductOptions as $actualBundleProductOption) {
            $actualBundleProductOptionsToCompare[$actualBundleProductOption->getTitle()]['type']
                = $actualBundleProductOption->getType();
            foreach ($actualBundleProductOption->getProductLinks() as $productLink) {
                $actualBundleProductOptionsToCompare[$actualBundleProductOption->getTitle()]['product_links'][]
                    = $productLink->getSku();
            }
        }

        $this->assertEquals(count($expectedBundleProductOptions), count($actualBundleProductOptions));

        foreach ($expectedBundleProductOptionsToCompare as $key => $expectedBundleProductOption) {
            $this->assertEquals(
                $expectedBundleProductOption['type'],
                $actualBundleProductOptionsToCompare[$key]['type']
            );

            $expectedProductLinks = $expectedBundleProductOption['product_links'];
            $actualProductLinks = $actualBundleProductOptionsToCompare[$key]['product_links'];

            sort($expectedProductLinks);
            sort($actualProductLinks);

            $this->assertEquals($expectedProductLinks, $actualProductLinks);
        }
    }
}
