<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogUrlRewrite\Service\V1;

use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Store view service
 */
class StoreViewService
{
    /**
     * @var \Magento\Eav\Model\Config
     */
    protected $eavConfig;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @param Config $eavConfig
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(
        Config $eavConfig,
        ResourceConnection $resource
    ) {
        $this->eavConfig = $eavConfig;
        $this->connection = $resource->getConnection();
    }

    /**
     * Check that entity has overridden url key for specific store
     *
     * @param int $storeId
     * @param int $entityId
     * @param string $entityType
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function doesEntityHaveOverriddenUrlKeyForStore($storeId, $entityId, $entityType)
    {
        return $this->doesEntityHaveOverriddenUrlAttributeForStore($storeId, $entityId, $entityType, 'url_key');
    }

    /**
     * Check that entity has overridden url path for specific store
     *
     * @param int $storeId
     * @param int $entityId
     * @param string $entityType
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function doesEntityHaveOverriddenUrlPathForStore($storeId, $entityId, $entityType)
    {
        return $this->doesEntityHaveOverriddenUrlAttributeForStore($storeId, $entityId, $entityType, 'url_path');
    }

    /**
     * Check that entity has overridden url attribute for specific store
     *
     * @param int $storeId
     * @param int $entityId
     * @param string $entityType
     * @param mixed $attributeName
     * @throws \InvalidArgumentException
     * @return bool
     */
    protected function doesEntityHaveOverriddenUrlAttributeForStore($storeId, $entityId, $entityType, $attributeName)
    {
        $attribute = $this->eavConfig->getAttribute($entityType, $attributeName);
        if (!$attribute) {
            throw new \InvalidArgumentException(sprintf('Cannot retrieve attribute for entity type "%s"', $entityType));
        }
        $linkFieldName = $attribute->getEntity()->getLinkField();
        if (!$linkFieldName) {
            $linkFieldName = $this->getMetadataPool()->getMetadata(ProductInterface::class)->getLinkField();
        }
        $select = $this->connection->select()
            ->from(['e' => $attribute->getEntity()->getEntityTable()], [])
            ->join(
                ['e_attr' => $attribute->getBackendTable()],
                "e.{$linkFieldName} = e_attr.{$linkFieldName}",
                'store_id'
            )->where('e_attr.attribute_id = ?', $attribute->getId())
            ->where('e.entity_id = ?', $entityId);

        return in_array($storeId, $this->connection->fetchCol($select));
    }

    /**
     * Get product metadata pool
     *
     * @return \Magento\Framework\EntityManager\MetadataPool
     */
    private function getMetadataPool()
    {
        if (!$this->metadataPool) {
            $this->metadataPool = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\EntityManager\MetadataPool::class);
        }
        return $this->metadataPool;
    }
}
