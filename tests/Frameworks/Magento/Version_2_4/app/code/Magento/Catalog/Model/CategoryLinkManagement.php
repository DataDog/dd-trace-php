<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model;

/**
 * Represents Category Product Link Management class
 */
class CategoryLinkManagement implements \Magento\Catalog\Api\CategoryLinkManagementInterface
{
    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ResourceModel\Product
     */
    protected $productResource;

    /**
     * @var \Magento\Catalog\Api\CategoryLinkRepositoryInterface
     */
    protected $categoryLinkRepository;

    /**
     * @var \Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory
     */
    protected $productLinkFactory;

    /**
     * @var \Magento\Framework\Indexer\IndexerRegistry
     */
    protected $indexerRegistry;

    /**
     * CategoryLinkManagement constructor.
     *
     * @param \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository
     * @param \Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory $productLinkFactory
     */
    public function __construct(
        \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository,
        \Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory $productLinkFactory
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->productLinkFactory = $productLinkFactory;
    }

    /**
     * @inheritdoc
     */
    public function getAssignedProducts($categoryId)
    {
        $category = $this->categoryRepository->get($categoryId);

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $products */
        $products = $category->getProductCollection();
        $products->addFieldToSelect('position');
        $products->groupByAttribute($products->getProductEntityMetadata()->getIdentifierField());

        /** @var \Magento\Catalog\Api\Data\CategoryProductLinkInterface[] $links */
        $links = [];

        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($products->getItems() as $product) {
            /** @var \Magento\Catalog\Api\Data\CategoryProductLinkInterface $link */
            $link = $this->productLinkFactory->create();
            $link->setSku($product->getSku())
                ->setPosition($product->getData('cat_index_position'))
                ->setCategoryId($category->getId());
            $links[] = $link;
        }
        return $links;
    }

    /**
     * Assign product to given categories
     *
     * @param string $productSku
     * @param \int[] $categoryIds
     * @return bool
     */
    public function assignProductToCategories($productSku, array $categoryIds)
    {
        $product = $this->getProductRepository()->get($productSku);
        $assignedCategories = $this->getProductResource()->getCategoryIds($product);
        foreach (array_diff($assignedCategories, $categoryIds) as $categoryId) {
            $this->getCategoryLinkRepository()->deleteByIds($categoryId, $productSku);
        }

        foreach (array_diff($categoryIds, $assignedCategories) as $categoryId) {
            /** @var \Magento\Catalog\Api\Data\CategoryProductLinkInterface $categoryProductLink */
            $categoryProductLink = $this->productLinkFactory->create();
            $categoryProductLink->setSku($productSku);
            $categoryProductLink->setCategoryId($categoryId);
            $categoryProductLink->setPosition(0);
            $this->getCategoryLinkRepository()->save($categoryProductLink);
        }
        $productCategoryIndexer = $this->getIndexerRegistry()->get(Indexer\Product\Category::INDEXER_ID);
        if (!$productCategoryIndexer->isScheduled()) {
            $productCategoryIndexer->reindexRow($product->getId());
        }
        return true;
    }

    /**
     * Retrieve product repository instance
     *
     * @return \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private function getProductRepository()
    {
        if (null === $this->productRepository) {
            $this->productRepository = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        }
        return $this->productRepository;
    }

    /**
     * Retrieve product resource instance
     *
     * @return ResourceModel\Product
     */
    private function getProductResource()
    {
        if (null === $this->productResource) {
            $this->productResource = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Model\ResourceModel\Product::class);
        }
        return $this->productResource;
    }

    /**
     * Retrieve category link repository instance
     *
     * @return \Magento\Catalog\Api\CategoryLinkRepositoryInterface
     */
    private function getCategoryLinkRepository()
    {
        if (null === $this->categoryLinkRepository) {
            $this->categoryLinkRepository = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Catalog\Api\CategoryLinkRepositoryInterface::class);
        }
        return $this->categoryLinkRepository;
    }

    /**
     * Retrieve indexer registry instance
     *
     * @return \Magento\Framework\Indexer\IndexerRegistry
     */
    private function getIndexerRegistry()
    {
        if (null === $this->indexerRegistry) {
            $this->indexerRegistry = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Indexer\IndexerRegistry::class);
        }
        return $this->indexerRegistry;
    }
}
