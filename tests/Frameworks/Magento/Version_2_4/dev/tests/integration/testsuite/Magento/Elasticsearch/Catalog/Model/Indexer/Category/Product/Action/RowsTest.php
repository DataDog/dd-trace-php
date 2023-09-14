<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Elasticsearch\Catalog\Model\Indexer\Category\Product\Action;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\DefaultCategory;
use Magento\Catalog\Model\Indexer\Category\Product\Action\Rows;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\SearchCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\TestModuleCatalogSearch\Model\SearchEngineVersionReader;
use Magento\Framework\Search\EngineResolverInterface;

/**
 * Test for Magento\Catalog\Model\Indexer\Category\Product\Action\Rows class.
 * This test executable with any configuration of ES and should not be deleted with removal of ES2.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RowsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Rows
     */
    private $rowsIndexer;

    /**
     * @var DefaultCategory
     */
    private $defaultCategoryHelper;

    /**
     * @var SearchCollectionFactory
     */
    private $fulltextSearchCollectionFactory;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->rowsIndexer = $this->objectManager->get(Rows::class);
        $this->defaultCategoryHelper = $this->objectManager->get(DefaultCategory::class);
        $this->fulltextSearchCollectionFactory = $this->objectManager->get(SearchCollectionFactory::class);
    }

    /**
     * @inheritdoc
     */
    protected function assertPreConditions(): void
    {
        $currentEngine = $this->objectManager->get(EngineResolverInterface::class)->getCurrentSearchEngine();
        $installedEngine = $this->objectManager->get(SearchEngineVersionReader::class)->getFullVersion();
        $this->assertEquals(
            $installedEngine,
            $currentEngine,
            sprintf(
                'Search engine configuration "%s" is not compatible with the installed version',
                $currentEngine
            )
        );
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/category_tree_with_products.php
     * @magentoDataFixture Magento/CatalogSearch/_files/full_reindex.php
     * @magentoDataFixtureBeforeTransaction Magento/Catalog/_files/enable_reindex_schedule.php
     * @return void
     */
    public function testLoadWithFilterCatalogView()
    {
        $categoryA = $this->getCategory('Category A');
        $categoryB = $this->getCategory('Category B');
        $categoryC = $this->getCategory('Category C');

        /** Move $categoryB to $categoryA */
        $categoryB->move($categoryA->getId(), null);
        $this->rowsIndexer->execute(
            [
                $this->defaultCategoryHelper->getId(),
                $categoryA->getId(),
                $categoryB->getId(),
                $categoryC->getId(),
            ],
            true
        );

        $fulltextCollection = $this->fulltextSearchCollectionFactory->create()
            ->addCategoryFilter($categoryA);

        $this->assertProductsArePresentInCollection($fulltextCollection->getAllIds());
    }

    /**
     * Assert that expected products are present in collection.
     *
     * @param array $productIds
     *
     * @return void
     */
    private function assertProductsArePresentInCollection(array $productIds): void
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->get(ProductRepositoryInterface::class);

        $firstProductId = $productRepository->get('simpleB')->getId();
        $secondProductId = $productRepository->get('simpleC')->getId();

        $this->assertCount(2, $productIds);
        $this->assertContains($secondProductId, $productIds);
        $this->assertContains($firstProductId, $productIds);
    }

    /**
     * Gets category by name.
     *
     * @param string $name
     * @return CategoryInterface
     */
    private function getCategory(string $name): CategoryInterface
    {
        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $searchCriteria = $searchCriteriaBuilder->addFilter('name', $name)
            ->create();
        /** @var CategoryListInterface $repository */
        $repository = $this->objectManager->get(CategoryListInterface::class);
        $items = $repository->getList($searchCriteria)
            ->getItems();

        return array_pop($items);
    }
}
