<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Store\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\App\Config;

/**
 * Information Expert in stores handling
 */
class StoreRepository implements \Magento\Store\Api\StoreRepositoryInterface
{
    /**
     * @var StoreFactory
     */
    protected $storeFactory;

    /**
     * @var \Magento\Store\Model\ResourceModel\Store\CollectionFactory
     */
    protected $storeCollectionFactory;

    /**
     * @var \Magento\Store\Api\Data\StoreInterface[]
     */
    protected $entities = [];

    /**
     * @var \Magento\Store\Api\Data\StoreInterface[]
     */
    protected $entitiesById = [];

    /**
     * @var bool
     */
    protected $allLoaded = false;

    /**
     * @var Config
     */
    private $appConfig;

    /**
     * @param StoreFactory $storeFactory
     * @param \Magento\Store\Model\ResourceModel\Store\CollectionFactory $storeCollectionFactory
     */
    public function __construct(
        StoreFactory $storeFactory,
        \Magento\Store\Model\ResourceModel\Store\CollectionFactory $storeCollectionFactory
    ) {
        $this->storeFactory = $storeFactory;
        $this->storeCollectionFactory = $storeCollectionFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function get($code)
    {
        if (isset($this->entities[$code])) {
            return $this->entities[$code];
        }

        $storeData = $this->getAppConfig()->get('scopes', "stores/$code", []);
        $store = $this->storeFactory->create([
            'data' => $storeData
        ]);

        if ($store->getId() === null) {
            throw new NoSuchEntityException(
                __("The store that was requested wasn't found. Verify the store and try again.")
            );
        }
        $this->entities[$code] = $store;
        $this->entitiesById[$store->getId()] = $store;
        return $store;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveStoreByCode($code)
    {
        $store = $this->get($code);

        if (!$store->isActive()) {
            throw new StoreIsInactiveException();
        }
        return $store;
    }

    /**
     * {@inheritdoc}
     */
    public function getById($id)
    {
        if (isset($this->entitiesById[$id])) {
            return $this->entitiesById[$id];
        }

        $storeData = $this->getAppConfig()->get('scopes', "stores/$id", []);
        $store = $this->storeFactory->create([
            'data' => $storeData
        ]);

        if ($store->getId() === null) {
            throw new NoSuchEntityException(
                __("The store that was requested wasn't found. Verify the store and try again.")
            );
        }

        $this->entitiesById[$id] = $store;
        $this->entities[$store->getCode()] = $store;
        return $store;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveStoreById($id)
    {
        $store = $this->getById($id);

        if (!$store->isActive()) {
            throw new StoreIsInactiveException();
        }
        return $store;
    }

    /**
     * {@inheritdoc}
     */
    public function getList()
    {
        if ($this->allLoaded) {
            return $this->entities;
        }
        $stores = $this->getAppConfig()->get('scopes', "stores", []);
        foreach ($stores as $data) {
            $store = $this->storeFactory->create([
                'data' => $data
            ]);
            $this->entities[$store->getCode()] = $store;
            $this->entitiesById[$store->getId()] = $store;
        }
        $this->allLoaded = true;
        return $this->entities;
    }

    /**
     * Retrieve application config.
     *
     * @deprecated 100.1.3
     * @return Config
     */
    private function getAppConfig()
    {
        if (!$this->appConfig) {
            $this->appConfig = ObjectManager::getInstance()->get(Config::class);
        }
        return $this->appConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function clean()
    {
        $this->entities = [];
        $this->entitiesById = [];
        $this->allLoaded = false;
    }
}
