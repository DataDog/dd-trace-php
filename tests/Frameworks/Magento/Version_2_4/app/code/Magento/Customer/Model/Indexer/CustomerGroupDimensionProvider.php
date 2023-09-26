<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Model\Indexer;

use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Indexer\DimensionProviderInterface;

/**
 * Class CustomerGroupDimensionProvider
 */
class CustomerGroupDimensionProvider implements DimensionProviderInterface
{
    /**
     * Name for customer group dimension for multidimensional indexer
     * 'cg' - stands for 'customer_group'
     */
    const DIMENSION_NAME = 'cg';

    /**
     * @var CustomerGroupCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var \SplFixedArray
     */
    private $customerGroupsDataIterator;

    /**
     * @var DimensionFactory
     */
    private $dimensionFactory;

    /**
     * @param CustomerGroupCollectionFactory $collectionFactory
     * @param DimensionFactory $dimensionFactory
     */
    public function __construct(CustomerGroupCollectionFactory $collectionFactory, DimensionFactory $dimensionFactory)
    {
        $this->dimensionFactory = $dimensionFactory;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @inheritdoc
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->getCustomerGroups() as $customerGroup) {
            yield $this->dimensionFactory->create(self::DIMENSION_NAME, (string)$customerGroup);
        }
    }

    /**
     * Get Customer Groups
     *
     * @return array
     */
    private function getCustomerGroups(): array
    {
        if ($this->customerGroupsDataIterator === null) {
            $customerGroups = $this->collectionFactory->create()->getAllIds();
            $this->customerGroupsDataIterator = is_array($customerGroups) ? $customerGroups : [];
        }

        return $this->customerGroupsDataIterator;
    }
}
