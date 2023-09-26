<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Model\ResourceModel\Db\VersionControl;

use Magento\Framework\DataObject;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;

class AddressSnapshot extends Snapshot
{
    /**
     * {@inheritdoc}
     */
    public function isModified(DataObject $entity)
    {
        $result = parent::isModified($entity);

        if (!$result
            && !$entity->getIsCustomerSaveTransaction()
            && $this->isAddressDefault($entity)
        ) {
            return true;
        }

        return $result;
    }

    /**
     * Checks if address has chosen as default and has had an id
     *
     * @param DataObject $entity
     * @return bool
     */
    private function isAddressDefault(DataObject $entity)
    {
        return $entity->getId() && ($entity->getIsDefaultBilling() || $entity->getIsDefaultShipping());
    }
}
