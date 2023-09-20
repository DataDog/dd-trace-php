<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Tests product model:
 * - general behaviour is tested (external interaction and pricing is not tested there)
 *
 * @see \Magento\Catalog\Model\ProductExternalTest
 * @see \Magento\Catalog\Model\ProductPriceTest
 * @magentoDataFixture Magento/Catalog/_files/categories.php
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ProductTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var Product
     */
    protected $_model;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->_model = $this->objectManager->create(Product::class);
    }

    /**
     * @inheritdoc
     */
    public static function tearDownAfterClass(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Catalog\Model\Product\Media\Config $config */
        $config = $objectManager->get(\Magento\Catalog\Model\Product\Media\Config::class);

        /** @var \Magento\Framework\Filesystem\Directory\WriteInterface $mediaDirectory */
        $mediaDirectory = $objectManager->get(
            \Magento\Framework\Filesystem::class
        )->getDirectoryWrite(
            DirectoryList::MEDIA
        );

        if ($mediaDirectory->isExist($config->getBaseMediaPath())) {
            $mediaDirectory->delete($config->getBaseMediaPath());
        }
        if ($mediaDirectory->isExist($config->getBaseTmpMediaPath())) {
            $mediaDirectory->delete($config->getBaseTmpMediaPath());
        }
    }

    /**
     * Test can affect options
     *
     * @return void
     */
    public function testCanAffectOptions()
    {
        $this->assertFalse($this->_model->canAffectOptions());
        $this->_model->canAffectOptions(true);
        $this->assertTrue($this->_model->canAffectOptions());
    }

    /**
     * Test CRUD
     *
     * @magentoDbIsolation enabled
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     */
    public function testCRUD()
    {
        $this->_model->setTypeId(
            'simple'
        )->setAttributeSetId(
            4
        )->setName(
            'Simple Product 1'
        )->setSku(
            uniqid()
        )->setPrice(
            10
        )->setMetaTitle(
            'meta title'
        )->setMetaKeyword(
            'meta keyword'
        )->setMetaDescription(
            'meta description'
        )->setVisibility(
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
        )->setStatus(
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        );
        $crud = new \Magento\TestFramework\Entity($this->_model, ['sku' => uniqid()]);
        $crud->testCrud();
    }

    /**
     * Test clean cache
     *
     * @return void
     */
    public function testCleanCache()
    {
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\App\CacheInterface::class
        )->save(
            'test',
            'catalog_product_999',
            ['catalog_product_999']
        );
        // potential bug: it cleans by cache tags, generated from its ID, which doesn't make much sense
        $this->_model->setId(999)->cleanCache();
        $this->assertFalse(
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
                \Magento\Framework\App\CacheInterface::class
            )->load(
                'catalog_product_999'
            )
        );
    }

    /**
     * Test add image to media gallery
     *
     * @return void
     */
    public function testAddImageToMediaGallery()
    {
        // Model accepts only files in tmp media path, we need to copy fixture file there
        $mediaFile = $this->_copyFileToBaseTmpMediaPath(dirname(__DIR__) . '/_files/magento_image.jpg');

        $this->_model->addImageToMediaGallery($mediaFile);
        $gallery = $this->_model->getData('media_gallery');
        $this->assertNotEmpty($gallery);
        $this->assertTrue(isset($gallery['images'][0]['file']));
        $this->assertStringStartsWith('/m/a/magento_image', $gallery['images'][0]['file']);
        $this->assertTrue(isset($gallery['images'][0]['position']));
        $this->assertTrue(isset($gallery['images'][0]['disabled']));
        $this->assertArrayHasKey('label', $gallery['images'][0]);
    }

    /**
     * Copy file to media tmp directory and return it's name
     *
     * @param string $sourceFile
     * @return string
     */
    protected function _copyFileToBaseTmpMediaPath($sourceFile)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Catalog\Model\Product\Media\Config $config */
        $config = $objectManager->get(\Magento\Catalog\Model\Product\Media\Config::class);

        /** @var \Magento\Framework\Filesystem\Directory\WriteInterface $mediaDirectory */
        $mediaDirectory = $objectManager->get(
            \Magento\Framework\Filesystem::class
        )->getDirectoryWrite(
            DirectoryList::MEDIA
        );

        $mediaDirectory->create($config->getBaseTmpMediaPath());
        $targetFile = $config->getTmpMediaPath(basename($sourceFile));
        copy($sourceFile, $mediaDirectory->getAbsolutePath($targetFile));

        return $targetFile;
    }

    /**
     * Test duplicate method
     *
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     */
    public function testDuplicate()
    {
        $this->_model = $this->productRepository->get('simple');

        // fixture
        /** @var \Magento\Catalog\Model\Product\Copier $copier */
        $copier = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Catalog\Model\Product\Copier::class
        );
        $duplicate = $copier->copy($this->_model);
        try {
            $this->assertNotEmpty($duplicate->getId());
            $this->assertNotEquals($duplicate->getId(), $this->_model->getId());
            $this->assertNotEquals($duplicate->getSku(), $this->_model->getSku());
            $this->assertEquals(
                \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED,
                $duplicate->getStatus()
            );
            $this->assertEquals(\Magento\Store\Model\Store::DEFAULT_STORE_ID, $duplicate->getStoreId());
            $this->_undo($duplicate);
        } catch (\Exception $e) {
            $this->_undo($duplicate);
            throw $e;
        }
    }

    /**
     * Test duplicate sku generation
     *
     * @magentoAppArea adminhtml
     */
    public function testDuplicateSkuGeneration()
    {
        $this->_model = $this->productRepository->get('simple');

        $this->assertEquals('simple', $this->_model->getSku());
        /** @var \Magento\Catalog\Model\Product\Copier $copier */
        $copier = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Catalog\Model\Product\Copier::class
        );
        $duplicate = $copier->copy($this->_model);
        $this->assertEquals('simple-5', $duplicate->getSku());
    }

    /**
     * Delete model
     *
     * @param \Magento\Framework\Model\AbstractModel $duplicate
     */
    protected function _undo($duplicate)
    {
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Store\Model\StoreManagerInterface::class
        )->getStore()->setId(
            \Magento\Store\Model\Store::DEFAULT_STORE_ID
        );
        $duplicate->delete();
    }

    /**
     * Test visibility api
     *
     * @covers \Magento\Catalog\Model\Product::getVisibleInCatalogStatuses
     * @covers \Magento\Catalog\Model\Product::getVisibleStatuses
     * @covers \Magento\Catalog\Model\Product::isVisibleInCatalog
     * @covers \Magento\Catalog\Model\Product::getVisibleInSiteVisibilities
     * @covers \Magento\Catalog\Model\Product::isVisibleInSiteVisibility
     */
    public function testVisibilityApi()
    {
        $this->assertEquals(
            [\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED],
            $this->_model->getVisibleInCatalogStatuses()
        );
        $this->assertEquals(
            [\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED],
            $this->_model->getVisibleStatuses()
        );

        $this->_model->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED);
        $this->assertFalse($this->_model->isVisibleInCatalog());

        $this->_model->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $this->assertTrue($this->_model->isVisibleInCatalog());

        $this->assertEquals(
            [
                \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH,
                \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
                \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
            ],
            $this->_model->getVisibleInSiteVisibilities()
        );

        $this->assertFalse($this->_model->isVisibleInSiteVisibility());
        $this->_model->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH);
        $this->assertTrue($this->_model->isVisibleInSiteVisibility());
        $this->_model->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG);
        $this->assertTrue($this->_model->isVisibleInSiteVisibility());
        $this->_model->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
        $this->assertTrue($this->_model->isVisibleInSiteVisibility());
    }

    /**
     * Test isDuplicable and setIsDuplicable methods
     *
     * @covers \Magento\Catalog\Model\Product::isDuplicable
     * @covers \Magento\Catalog\Model\Product::setIsDuplicable
     */
    public function testIsDuplicable()
    {
        $this->assertTrue($this->_model->isDuplicable());
        $this->_model->setIsDuplicable(0);
        $this->assertFalse($this->_model->isDuplicable());
    }

    /**
     * Test isSalable, isSaleable, isAvailable and isInStock methods
     *
     * @covers \Magento\Catalog\Model\Product::isSalable
     * @covers \Magento\Catalog\Model\Product::isSaleable
     * @covers \Magento\Catalog\Model\Product::isAvailable
     * @covers \Magento\Catalog\Model\Product::isInStock
     */
    public function testIsSalable()
    {
        $this->_model = $this->productRepository->get('simple');

        // fixture
        $this->assertTrue((bool)$this->_model->isSalable());
        $this->assertTrue((bool)$this->_model->isSaleable());
        $this->assertTrue((bool)$this->_model->isAvailable());
        $this->assertTrue($this->_model->isInStock());
    }

    /**
     * Test isSalable method when Status is disabled
     *
     * @covers \Magento\Catalog\Model\Product::isSalable
     * @covers \Magento\Catalog\Model\Product::isSaleable
     * @covers \Magento\Catalog\Model\Product::isAvailable
     * @covers \Magento\Catalog\Model\Product::isInStock
     */
    public function testIsNotSalableWhenStatusDisabled()
    {
        $this->_model = $this->productRepository->get('simple');

        $this->_model->setStatus(0);
        $this->assertFalse((bool)$this->_model->isSalable());
        $this->assertFalse((bool)$this->_model->isSaleable());
        $this->assertFalse((bool)$this->_model->isAvailable());
        $this->assertFalse($this->_model->isInStock());
    }

    /**
     * Test isVirtual and getIsVirtual methods
     *
     * @covers \Magento\Catalog\Model\Product::isVirtual
     * @covers \Magento\Catalog\Model\Product::getIsVirtual
     */
    public function testIsVirtual()
    {
        $this->assertFalse($this->_model->isVirtual());
        $this->assertFalse($this->_model->getIsVirtual());

        /** @var $model \Magento\Catalog\Model\Product */
        $model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Product::class,
            ['data' => ['type_id' => \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL]]
        );
        $this->assertTrue($model->isVirtual());
        $this->assertTrue($model->getIsVirtual());
    }

    /**
     * Test toArray method
     *
     * @return void
     */
    public function testToArray()
    {
        $this->assertEquals([], $this->_model->toArray());
        $this->_model->setSku('sku')->setName('name');
        $this->assertEquals(['sku' => 'sku', 'name' => 'name'], $this->_model->toArray());
    }

    /**
     * Test fromArray method
     *
     * @return void
     */
    public function testFromArray()
    {
        $this->_model->fromArray(['sku' => 'sku', 'name' => 'name', 'stock_item' => ['key' => 'value']]);
        $this->assertEquals(['sku' => 'sku', 'name' => 'name'], $this->_model->getData());
    }

    /**
     * Test set original data backend
     *
     * @magentoAppArea adminhtml
     */
    public function testSetOrigDataBackend()
    {
        $this->assertEmpty($this->_model->getOrigData());
        $this->_model->setOrigData('key', 'value');
        $this->assertEquals('value', $this->_model->getOrigData('key'));
    }

    /**
     * Test reset method
     *
     * @magentoAppArea frontend
     */
    public function testReset()
    {
        $model = $this->_model;

        $this->_assertEmpty($model);

        $this->_model->setData('key', 'value');
        $this->_model->reset();
        $this->_assertEmpty($model);

        $this->_model->setOrigData('key', 'value');
        $this->_model->reset();
        $this->_assertEmpty($model);

        $this->_model->addCustomOption('key', 'value');
        $this->_model->reset();
        $this->_assertEmpty($model);

        $this->_model->canAffectOptions(true);
        $this->_model->reset();
        $this->_assertEmpty($model);
    }

    /**
     * Check is model empty or not
     *
     * @param \Magento\Framework\Model\AbstractModel $model
     */
    protected function _assertEmpty($model)
    {
        $this->assertEquals([], $model->getData());
        $this->assertEmpty($model->getOrigData());
        $this->assertEquals([], $model->getCustomOptions());
        // impossible to test $_optionInstance
        $this->assertFalse($model->canAffectOptions());
    }

    /**
     * Test is products has sku
     *
     * @magentoDataFixture Magento/Catalog/_files/multiple_products.php
     */
    public function testIsProductsHasSku()
    {
        $product1 = $this->productRepository->get('simple1');
        $product2 = $this->productRepository->get('simple2');

        $this->assertTrue(
            $this->_model->isProductsHasSku(
                [$product1->getId(), $product2->getId()]
            )
        );
    }

    /**
     * Test process by request
     *
     * @return void
     */
    public function testProcessBuyRequest()
    {
        $request = new \Magento\Framework\DataObject();
        $result = $this->_model->processBuyRequest($request);
        $this->assertInstanceOf(\Magento\Framework\DataObject::class, $result);
        $this->assertArrayHasKey('errors', $result->getData());
    }

    /**
     * Test validate method
     *
     * @return void
     */
    public function testValidate()
    {
        $this->_model->setTypeId(
            'simple'
        )->setAttributeSetId(
            4
        )->setName(
            'Simple Product'
        )->setSku(
            uniqid('', true) . uniqid('', true) . uniqid('', true)
        )->setPrice(
            10
        )->setMetaTitle(
            'meta title'
        )->setMetaKeyword(
            'meta keyword'
        )->setMetaDescription(
            'meta description'
        )->setVisibility(
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
        )->setStatus(
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->setCollectExceptionMessages(
            true
        );
        $validationResult = $this->_model->validate();
        $this->assertEquals('SKU length should be 64 characters maximum.', $validationResult['sku']);
        unset($validationResult['sku']);
        foreach ($validationResult as $error) {
            $this->assertTrue($error);
        }
    }

    /**
     * Test validate unique input attribute value
     *
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/products_with_unique_input_attribute.php
     */
    public function testValidateUniqueInputAttributeValue()
    {
        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
        $attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->loadByCode(\Magento\Catalog\Model\Product::ENTITY, 'unique_input_attribute');
        $this->_model->setTypeId(
            'simple'
        )->setAttributeSetId(
            4
        )->setName(
            'Simple Product with non-unique value'
        )->setSku(
            'some product SKU'
        )->setPrice(
            10
        )->setMetaTitle(
            'meta title'
        )->setData(
            $attribute->getAttributeCode(),
            'unique value'
        )->setVisibility(
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
        )->setStatus(
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->setCollectExceptionMessages(
            true
        );

        $validationResult = $this->_model->validate();
        $this->assertCount(1, $validationResult);

        $this->assertContains('The value of the "' . $attribute->getDefaultFrontendLabel() .
            '" attribute isn\'t unique. Set a unique value and try again.',$validationResult);
    }

    /**
     * Test validate unique input attribute value on the same product
     *
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/products_with_unique_input_attribute.php
     */
    public function testValidateUniqueInputAttributeOnTheSameProduct()
    {
        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
        $attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->loadByCode(\Magento\Catalog\Model\Product::ENTITY, 'unique_input_attribute');
        $this->_model = $this->_model->loadByAttribute(
            'sku',
            'simple product with unique input attribute'
        );
        $this->_model->setTypeId(
            'simple'
        )->setAttributeSetId(
            4
        )->setName(
            'Simple Product with non-unique value'
        )->setSku(
            'some product SKU'
        )->setPrice(
            10
        )->setMetaTitle(
            'meta title'
        )->setData(
            $attribute->getAttributeCode(),
            'unique value'
        )->setVisibility(
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
        )->setStatus(
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->setCollectExceptionMessages(
            true
        );

        $validationResult = $this->_model->validate();
        $this->assertTrue($validationResult);
    }

    /**
     * Tests Customizable Options price values including negative value.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_custom_options.php
     * @magentoAppIsolation enabled
     */
    public function testGetOptions()
    {
        $this->_model = $this->productRepository->get('simple_with_custom_options');
        $options = $this->_model->getOptions();
        $this->assertNotEmpty($options);
        $expectedValue = [
            '3-1-select' => -3000.00,
            '3-2-select' => 5000.00,
            '4-1-radio' => 600.234,
            '4-2-radio' => 40000.00
        ];
        foreach ($options as $option) {
            if (!$option->getValues()) {
                continue;
            }
            foreach ($option->getValues() as $value) {
                $this->assertEquals($expectedValue[$value->getSku()], (float)$value->getPrice());
            }
        }
    }

    /**
     * Check stock status changing if backorders functionality enabled.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple_out_of_stock.php
     * @dataProvider productWithBackordersDataProvider
     * @param int $qty
     * @param int $stockStatus
     * @param bool $expectedStockStatus
     *
     * @return void
     */
    public function testSaveWithBackordersEnabled(int $qty, int $stockStatus, bool $expectedStockStatus): void
    {
        $product = $this->productRepository->get('simple-out-of-stock', true, null, true);
        $stockItem = $product->getExtensionAttributes()->getStockItem();
        $this->assertFalse($stockItem->getIsInStock());
        $stockData = [
            'backorders' => 1,
            'qty' => $qty,
            'is_in_stock' => $stockStatus,
        ];
        $product->setStockData($stockData);
        $product->save();
        $stockItem = $product->getExtensionAttributes()->getStockItem();

        $this->assertEquals($expectedStockStatus, $stockItem->getIsInStock());
    }

    /**
     * Checking enable/disable product when Catalog Flat Product is enabled
     *
     * @magentoAppArea frontend
     * @magentoDbIsolation disabled
     * @magentoConfigFixture current_store catalog/frontend/flat_catalog_product 1
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     *
     * @return void
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function testProductStatusWhenCatalogFlatProductIsEnabled()
    {
        // check if product flat table is enabled
        $productFlatState = $this->objectManager->get(\Magento\Catalog\Model\Indexer\Product\Flat\State::class);
        $this->assertTrue($productFlatState->isFlatEnabled());
        // run reindex to create product flat table
        $productFlatProcessor = $this->objectManager->get(\Magento\Catalog\Model\Indexer\Product\Flat\Processor::class);
        $productFlatProcessor->reindexAll();
        // get created simple product
        $product = $this->productRepository->get('simple');
        // get db connection and the product flat table name
        $resource = $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        /** @var \Magento\Framework\DB\Adapter\AdapterInterface $connection */
        $connection = $resource->getConnection();
        $productFlatTableName = $productFlatState->getFlatIndexerHelper()->getFlatTableName(1);
        // generate sql query to find created simple product in the flat table
        $sql = $connection->select()->from($productFlatTableName)->where('sku =?', $product->getSku());
        // check if the product exists in the product flat table
        $products = $connection->fetchAll($sql);
        $this->assertEquals(Status::STATUS_ENABLED, $product->getStatus());
        $this->assertNotEmpty($products);
        // disable product
        $product->setStatus(Status::STATUS_DISABLED);
        $product = $this->productRepository->save($product);
        // check if the product exists in the product flat table
        $products = $connection->fetchAll($sql);
        $this->assertEquals(Status::STATUS_DISABLED, $product->getStatus());
        $this->assertEmpty($products);
    }

    /**
     * DataProvider for the testSaveWithBackordersEnabled()
     *
     * @return array
     */
    public function productWithBackordersDataProvider(): array
    {
        return [
            [0, 0, false],
            [0, 1, true],
            [-1, 0, false],
            [-1, 1, true],
            [1, 1, true],
        ];
    }
}
