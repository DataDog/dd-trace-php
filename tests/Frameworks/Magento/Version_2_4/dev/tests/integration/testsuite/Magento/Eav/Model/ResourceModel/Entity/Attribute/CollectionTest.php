<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Model\ResourceModel\Entity\Attribute;

class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::class
        );
    }

    /**
     * Returns array of set ids, present in collection attributes
     *
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection $collection
     * @return array
     */
    protected function _getSets($collection)
    {
        $collection->addSetInfo();

        $sets = [];
        foreach ($collection as $attribute) {
            foreach (array_keys($attribute->getAttributeSetInfo()) as $setId) {
                $sets[$setId] = $setId;
            }
        }
        return array_values($sets);
    }

    public function testSetAttributeGroupFilter()
    {
        $collection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::class
        );
        $groupsPresent = $this->_getGroups($collection);
        $includeGroupId = current($groupsPresent);

        $this->_model->setAttributeGroupFilter($includeGroupId);
        $groups = $this->_getGroups($this->_model);

        $this->assertEquals([$includeGroupId], $groups);
    }

    /**
     * Test if getAllIds method return results after using setInAllAttributeSetsFilter method
     *
     * @covers \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::setInAllAttributeSetsFilter()
     * @covers \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::getAllIds()
     */
    public function testSetInAllAttributeSetsFilterWithGetAllIds()
    {
        $sets = [1];
        $this->_model->setInAllAttributeSetsFilter($sets);
        $attributeIds = $this->_model->getAllIds();
        $this->assertGreaterThan(0, count($attributeIds));
    }

    /**
     * Returns array of group ids, present in collection attributes
     *
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection $collection
     * @return array
     */
    protected function _getGroups($collection)
    {
        $collection->addSetInfo();

        $groups = [];
        foreach ($collection as $attribute) {
            foreach ($attribute->getAttributeSetInfo() as $setInfo) {
                $groupId = $setInfo['group_id'];
                $groups[$groupId] = $groupId;
            }
        }
        return array_values($groups);
    }

    public function testAddAttributeGrouping()
    {
        $select = $this->_model->getSelect();
        $this->assertEmpty($select->getPart(\Magento\Framework\DB\Select::GROUP));
        $this->_model->addAttributeGrouping();
        $this->assertEquals(['main_table.attribute_id'], $select->getPart(\Magento\Framework\DB\Select::GROUP));
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $reflection = new \ReflectionObject($this);
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic() && 0 !== strpos($property->getDeclaringClass()->getName(), 'PHPUnit')) {
                $property->setAccessible(true);
                $property->setValue($this, null);
            }
        }
    }
}
