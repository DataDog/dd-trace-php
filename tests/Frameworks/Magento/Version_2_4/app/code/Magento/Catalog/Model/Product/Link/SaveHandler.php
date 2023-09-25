<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Link;

use Magento\Catalog\Api\ProductLinkRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Link;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Api\Data\ProductLinkInterface;

/**
 * Save product links.
 */
class SaveHandler
{
    /**
     * @var ProductLinkRepositoryInterface
     */
    protected $productLinkRepository;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var Link
     */
    private $linkResource;

    /**
     * SaveHandler constructor.
     * @param MetadataPool $metadataPool
     * @param Link $linkResource
     * @param ProductLinkRepositoryInterface $productLinkRepository
     */
    public function __construct(
        MetadataPool $metadataPool,
        Link $linkResource,
        ProductLinkRepositoryInterface $productLinkRepository
    ) {
        $this->metadataPool = $metadataPool;
        $this->linkResource = $linkResource;
        $this->productLinkRepository = $productLinkRepository;
    }

    /**
     * Save product links for the product.
     *
     * @param string $entityType Product type.
     * @param \Magento\Catalog\Api\Data\ProductInterface $entity
     * @return \Magento\Catalog\Api\Data\ProductInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute($entityType, $entity)
    {
        $link = $entity->getData($this->metadataPool->getMetadata($entityType)->getLinkField());
        if ($this->linkResource->hasProductLinks($link)) {
            foreach ($this->productLinkRepository->getList($entity) as $link) {
                $this->productLinkRepository->delete($link);
            }
        }

        // Build links per type
        /** @var ProductLinkInterface[][] $linksByType */
        $linksByType = [];
        foreach ($entity->getProductLinks() as $link) {
            $linksByType[$link->getLinkType()][] = $link;
        }

        // Set array position as a fallback position if necessary
        foreach ($linksByType as $linkType => $links) {
            if (!$this->hasPosition($links)) {
                array_walk(
                    $linksByType[$linkType],
                    function (ProductLinkInterface $productLink, $position) {
                        $productLink->setPosition(++$position);
                    }
                );
            }
        }

        // Flatten multi-dimensional linksByType in ProductLinks
        /** @var ProductLinkInterface[] $productLinks */
        $productLinks = array_reduce($linksByType, 'array_merge', []);

        if (count($productLinks) > 0) {
            foreach ($entity->getProductLinks() as $link) {
                $this->productLinkRepository->save($link);
            }
        }
        return $entity;
    }

    /**
     * Check if at least one link without position
     *
     * @param ProductLinkInterface[] $links
     * @return bool
     */
    private function hasPosition(array $links): bool
    {
        foreach ($links as $link) {
            if ($link->getPosition() === null) {
                return false;
            }
        }
        return true;
    }
}
