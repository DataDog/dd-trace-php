<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;

/**
 * Class \Magento\Catalog\Model\ProductAttributeGroupRepository
 */
class ProductAttributeGroupRepository implements \Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface
{
    /**
     * @var \Magento\Eav\Api\AttributeGroupRepositoryInterface
     */
    protected $groupRepository;

    /**
     * @var \Magento\Catalog\Model\Product\Attribute\GroupFactory
     */
    protected $groupFactory;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Group
     */
    protected $groupResource;

    /**
     * @param \Magento\Eav\Api\AttributeGroupRepositoryInterface $groupRepository
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Group $groupResource
     * @param Product\Attribute\GroupFactory $groupFactory
     */
    public function __construct(
        \Magento\Eav\Api\AttributeGroupRepositoryInterface $groupRepository,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\Group $groupResource,
        \Magento\Catalog\Model\Product\Attribute\GroupFactory $groupFactory
    ) {
        $this->groupRepository = $groupRepository;
        $this->groupResource = $groupResource;
        $this->groupFactory = $groupFactory;
    }

    /**
     * @inheritdoc
     */
    public function save(\Magento\Eav\Api\Data\AttributeGroupInterface $group)
    {
        /** @var \Magento\Catalog\Model\Product\Attribute\Group $group */
        $extensionAttributes = $group->getExtensionAttributes();
        if ($extensionAttributes) {
            $group->setSortOrder($extensionAttributes->getSortOrder());
            $group->setAttributeGroupCode($extensionAttributes->getAttributeGroupCode());
        }
        return $this->groupRepository->save($group);
    }

    /**
     * @inheritdoc
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
    {
        return $this->groupRepository->getList($searchCriteria);
    }

    /**
     * @inheritdoc
     */
    public function get($groupId)
    {
        /** @var \Magento\Catalog\Model\Product\Attribute\Group $group */
        $group = $this->groupFactory->create();
        $this->groupResource->load($group, $groupId);
        if (!$group->getId()) {
            throw new NoSuchEntityException(
                __('The group with the "%1" ID doesn\'t exist. Verify the ID and try again.', $groupId)
            );
        }
        return $group;
    }

    /**
     * @inheritdoc
     */
    public function deleteById($groupId)
    {
        $this->delete(
            $this->get($groupId)
        );
        return true;
    }

    /**
     * @inheritdoc
     */
    public function delete(\Magento\Eav\Api\Data\AttributeGroupInterface $group)
    {
        /** @var \Magento\Catalog\Model\Product\Attribute\Group $group */
        if ($group->hasSystemAttributes()) {
            throw new StateException(
                __("The attribute group can't be deleted because it contains system attributes.")
            );
        }
        return $this->groupRepository->delete($group);
    }
}
