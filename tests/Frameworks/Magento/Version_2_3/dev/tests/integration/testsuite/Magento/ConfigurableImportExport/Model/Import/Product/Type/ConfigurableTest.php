<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableImportExport\Model\Import\Product\Type;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * @magentoAppArea adminhtml
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfigurableTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Configurable product test Type
     */
    const TEST_PRODUCT_TYPE = 'configurable';

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product
     */
    protected $model;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\EntityManager\EntityMetadata
     */
    protected $productMetadata;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->model = $this->objectManager->create(\Magento\CatalogImportExport\Model\Import\Product::class);
        /** @var \Magento\Framework\EntityManager\MetadataPool $metadataPool */
        $metadataPool = $this->objectManager->get(\Magento\Framework\EntityManager\MetadataPool::class);
        $this->productMetadata = $metadataPool->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
    }

    public function configurableImportDataProvider()
    {
        return [
            'Configurable 1' => [
                __DIR__ . '/../../_files/import_configurable.csv',
                'Configurable 1',
                ['Configurable 1-Option 1', 'Configurable 1-Option 2'],
            ],
            '12345' => [
                __DIR__ . '/../../_files/import_configurable_12345.csv',
                '12345',
                ['Configurable 1-Option 1', 'Configurable 1-Option 2'],
            ],
        ];
    }

    /**
     * @param $pathToFile Path to import file
     * @param $productName Name/sku of configurable product
     * @param $optionSkuList Name of variations for configurable product
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     * @dataProvider configurableImportDataProvider
     */
    public function testConfigurableImport($pathToFile, $productName, $optionSkuList)
    {
        $filesystem = $this->objectManager->create(
            \Magento\Framework\Filesystem::class
        );

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            \Magento\ImportExport\Model\Import\Source\Csv::class,
            [
                'file' => $pathToFile,
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource(
            $source
        )->setParameters(
            [
                'behavior' => \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND,
                'entity' => 'catalog_product'
            ]
        )->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        /** @var \Magento\Catalog\Model\ResourceModel\Product $resource */
        $resource = $this->objectManager->get(\Magento\Catalog\Model\ResourceModel\Product::class);
        $productId = $resource->getIdBySku($productName);
        $this->assertIsNumeric($productId);
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->objectManager->get(ProductRepositoryInterface::class)->getById($productId);

        $this->assertFalse($product->isObjectNew());
        $this->assertEquals($productName, $product->getName());
        $this->assertEquals(self::TEST_PRODUCT_TYPE, $product->getTypeId());

        $optionCollection = $product->getTypeInstance()->getConfigurableOptions($product);
        foreach ($optionCollection as $option) {
            foreach ($option as $optionData) {
                $this->assertContains($optionData['sku'], $optionSkuList);
            }
        }

        $optionIdList = $resource->getProductsIdsBySkus($optionSkuList);
        foreach ($optionIdList as $optionId) {
            $this->assertArrayHasKey($optionId, $product->getExtensionAttributes()->getConfigurableProductLinks());
        }

        $configurableOptionCollection = $product->getExtensionAttributes()->getConfigurableProductOptions();
        $this->assertCount(1, $configurableOptionCollection);
        foreach ($configurableOptionCollection as $option) {
            $optionData = $option->getData();
            $this->assertArrayHasKey('product_super_attribute_id', $optionData);
            $this->assertArrayHasKey('product_id', $optionData);
            $this->assertEquals($product->getData($this->productMetadata->getLinkField()), $optionData['product_id']);
            $this->assertArrayHasKey('attribute_id', $optionData);
            $this->assertArrayHasKey('position', $optionData);
            $this->assertArrayHasKey('extension_attributes', $optionData);
            $this->assertArrayHasKey('product_attribute', $optionData);
            $productAttributeData = $optionData['product_attribute']->getData();
            $this->assertArrayHasKey('attribute_id', $productAttributeData);
            $this->assertArrayHasKey('entity_type_id', $productAttributeData);
            $this->assertArrayHasKey('attribute_code', $productAttributeData);
            $this->assertEquals('test_configurable', $productAttributeData['attribute_code']);
            $this->assertArrayHasKey('frontend_label', $productAttributeData);
            $this->assertEquals('Test Configurable', $productAttributeData['frontend_label']);
            $this->assertArrayHasKey('label', $optionData);
            $this->assertEquals('test_configurable_custom_label', $optionData['label']);
            $this->assertArrayHasKey('use_default', $optionData);
            $this->assertArrayHasKey('options', $optionData);
            $this->assertEquals('Option 1', $optionData['options'][0]['label']);
            $this->assertEquals('Option 1', $optionData['options'][0]['default_label']);
            $this->assertEquals('Option 1', $optionData['options'][0]['store_label']);
            $this->assertEquals('Option 2', $optionData['options'][1]['label']);
            $this->assertEquals('Option 2', $optionData['options'][1]['default_label']);
            $this->assertEquals('Option 2', $optionData['options'][1]['store_label']);
            $this->assertArrayHasKey('values', $optionData);
            $valuesData = $optionData['values'];
            $this->assertCount(2, $valuesData);
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/enable_reindex_schedule.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     * @magentoDbIsolation disabled
     */
    public function testConfigurableImportWithMultipleStores()
    {
        $productSku = 'Configurable 1';
        $products = [
            'default' => 'Configurable 1',
            'fixture_second_store' => 'Configurable 1 Second Store'
        ];
        $filesystem = $this->objectManager->create(
            \Magento\Framework\Filesystem::class
        );

        $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->create(
            \Magento\ImportExport\Model\Import\Source\Csv::class,
            [
                'file' =>  __DIR__ . '/../../_files/import_configurable_for_multiple_store_views.csv',
                'directory' => $directory
            ]
        );
        $errors = $this->model->setSource(
            $source
        )->setParameters(
            [
                'behavior' => \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND,
                'entity' => 'catalog_product'
            ]
        )->validateData();

        $this->assertTrue($errors->getErrorsCount() == 0);
        $this->model->importData();

        foreach ($products as $storeCode => $productName) {
            $store = $this->objectManager->create(\Magento\Store\Model\Store::class);
            $store->load($storeCode, 'code');
            /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
            $productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            /** @var \Magento\Catalog\Api\Data\ProductInterface $product */
            $product = $productRepository->get($productSku, 0, $store->getId());
            $this->assertFalse($product->isObjectNew());
            $this->assertEquals($productName, $product->getName());
            $this->assertEquals(self::TEST_PRODUCT_TYPE, $product->getTypeId());
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/enable_reindex_schedule.php
     * @magentoDataFixture Magento/Store/_files/second_store.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     * @magentoDbIsolation disabled
     * @magentoAppArea adminhtml
     */
    public function testConfigurableImportWithStoreSpecifiedMainItem()
    {
        {
            $expectedErrorMessage = 'Product with assigned super attributes should not have specified "store_view_code"'
                . ' value';
            $filesystem = $this->objectManager->create(
                \Magento\Framework\Filesystem::class
            );

            $directory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
            $source = $this->objectManager->create(
                \Magento\ImportExport\Model\Import\Source\Csv::class,
                [
                    'file' =>  __DIR__ . '/../../_files/import_configurable_for_multiple_store_views_error.csv',
                    'directory' => $directory
                ]
            );
            $errors = $this->model->setSource(
                $source
            )->setParameters(
                [
                    'behavior' => \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND,
                    'entity' => 'catalog_product'
                ]
            )->validateData();

            $this->assertTrue($errors->getErrorsCount() == 1);
            $this->assertEquals($expectedErrorMessage, $errors->getAllErrors()[0]->getErrorMessage());
        }
    }
}
