<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Indexer\Category\Product\Plugin;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\AbstractModel;
use Magento\Catalog\Model\Indexer\Category\Product;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;

class StoreGroup
{
    /**
     * @var bool
     */
    private $needInvalidating;

    /**
     * @var IndexerRegistry
     */
    protected $indexerRegistry;

    /**
     * @var TableMaintainer
     */
    protected $tableMaintainer;

    /**
     * @param IndexerRegistry $indexerRegistry
     * @param TableMaintainer $tableMaintainer
     */
    public function __construct(
        IndexerRegistry $indexerRegistry,
        TableMaintainer $tableMaintainer
    ) {
        $this->indexerRegistry = $indexerRegistry;
        $this->tableMaintainer = $tableMaintainer;
    }

    /**
     * Check if need invalidate flat category indexer
     *
     * @param AbstractDb $subject
     * @param AbstractModel $group
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(AbstractDb $subject, AbstractModel $group)
    {
        $this->needInvalidating = $this->validate($group);
    }

    /**
     * Invalidate flat product
     *
     * @param AbstractDb $subject
     * @param AbstractDb $objectResource
     *
     * @return AbstractDb
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(AbstractDb $subject, AbstractDb $objectResource)
    {
        if ($this->needInvalidating) {
            $this->indexerRegistry->get(Product::INDEXER_ID)->invalidate();
        }

        return $objectResource;
    }

    /**
     * Validate changes for invalidating indexer
     *
     * @param AbstractModel $group
     * @return bool
     */
    protected function validate(AbstractModel $group)
    {
        return ($group->dataHasChangedFor('website_id') || $group->dataHasChangedFor('root_category_id'))
               && !$group->isObjectNew();
    }

    /**
     * Delete catalog_category_product indexer tables for deleted store group
     *
     * @param AbstractDb $subject
     * @param AbstractDb $objectResource
     * @param AbstractModel $storeGroup
     *
     * @return AbstractDb
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDelete(AbstractDb $subject, AbstractDb $objectResource, AbstractModel $storeGroup)
    {
        foreach ($storeGroup->getStores() as $store) {
            $this->tableMaintainer->dropTablesForStore((int)$store->getId());
        }
        return $objectResource;
    }
}
