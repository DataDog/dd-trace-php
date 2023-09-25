<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Eav\Test\Unit\Model\Entity\Attribute;

use Magento\Eav\Model\Entity\Attribute\Group;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class GroupTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Group
     */
    private $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $eventManagerMock;

    protected function setUp(): void
    {
        $this->resourceMock = $this->createMock(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Group::class);
        $translitFilter = $this->getMockBuilder(\Magento\Framework\Filter\Translit::class)
            ->disableOriginalConstructor()
            ->getMock();
        $translitFilter->expects($this->atLeastOnce())->method('filter')->willReturnArgument(0);

        $this->eventManagerMock = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $contextMock = $this->createMock(\Magento\Framework\Model\Context::class);
        $contextMock->expects($this->any())->method('getEventDispatcher')->willReturn($this->eventManagerMock);
        $constructorArguments = [
            'resource' => $this->resourceMock,
            'translitFilter' => $translitFilter,
            'context' => $contextMock,
            'reservedSystemNames' => ['configurable'],
        ];
        $objectManager = new ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Eav\Model\Entity\Attribute\Group::class,
            $constructorArguments
        );
    }

    /**
     * @dataProvider attributeGroupCodeDataProvider
     * @param string $groupName
     * @param string $groupCode
     */
    public function testBeforeSaveGeneratesGroupCodeBasedOnGroupName($groupName, $groupCode)
    {
        $this->model->setAttributeGroupName($groupName);
        $this->model->beforeSave();
        $this->assertEquals($groupCode, $this->model->getAttributeGroupCode());
    }

    /**
     * @return array
     */
    public function attributeGroupCodeDataProvider()
    {
        return [
            ['General Group', 'general-group'],
            ['configurable', md5('configurable')],
            ['configurAble', md5('configurable')],
            ['///', md5('///')],
        ];
    }
}
