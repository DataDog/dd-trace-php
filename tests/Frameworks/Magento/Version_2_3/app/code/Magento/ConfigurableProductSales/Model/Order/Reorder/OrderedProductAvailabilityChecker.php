<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProductSales\Model\Order\Reorder;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Reorder\OrderedProductAvailabilityCheckerInterface;
use Magento\Store\Model\Store;

/**
 * Class OrderedProductAvailabilityChecker
 */
class OrderedProductAvailabilityChecker implements OrderedProductAvailabilityCheckerInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MetadataPool $metadataPool
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        MetadataPool $metadataPool,
        ProductRepositoryInterface $productRepository
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->metadataPool = $metadataPool;
        $this->productRepository = $productRepository;
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(Item $item)
    {
        $buyRequest = $item->getBuyRequest();
        $superAttribute = $buyRequest->getData()['super_attribute'] ?? [];
        $connection = $this->getConnection();
        $select = $connection->select();
        $linkField = $this->getMetadata()->getLinkField();
        $parentItem = $this->productRepository->getById($item->getParentItem()->getProductId());
        $orderItemParentId = $parentItem->getData($linkField);
        $select->from(
            ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
            ['cpe.entity_id']
        )
            ->where('cpe.entity_id = ?', $item->getProductId());
        $select->join(
            ['cpsl' => $this->resourceConnection->getTableName('catalog_product_super_link')],
            sprintf(
                'cpe.entity_id = cpsl.product_id AND cpsl.parent_id = %d',
                $orderItemParentId
            ),
            []
        );
        foreach ($superAttribute as $attributeId => $attributeValue) {
            $select->join(
                ['cpid' . $attributeId => $this->resourceConnection->getTableName('catalog_product_entity_int')],
                sprintf(
                    'cpe.%1$s = cpid%2$d.%1$s AND cpid%2$d.attribute_id = %2$d AND cpid%2$d.store_id = %3$d',
                    $linkField,
                    $attributeId,
                    Store::DEFAULT_STORE_ID
                ),
                []
            )
                ->joinLeft(
                    ['cpis' . $attributeId => $this->resourceConnection->getTableName('catalog_product_entity_int')],
                    sprintf(
                        'cpe.%1$s = cpis%2$d.%1$s AND cpis%2$d.attribute_id = %2$d AND cpis%2$d.store_id = %3$d',
                        $linkField,
                        $attributeId,
                        $item->getStoreId()
                    ),
                    []
                )
                ->where(
                    sprintf(
                        '%s = ?',
                        $connection->getIfNullSql(
                            'cpis' . $attributeId . '.value',
                            'cpid' . $attributeId . '.value'
                        )
                    ),
                    $attributeValue
                );
        }
        return (bool)$connection->fetchCol($select);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * @return \Magento\Framework\EntityManager\EntityMetadataInterface
     */
    private function getMetadata()
    {
        return $this->metadataPool->getMetadata(ProductInterface::class);
    }
}
