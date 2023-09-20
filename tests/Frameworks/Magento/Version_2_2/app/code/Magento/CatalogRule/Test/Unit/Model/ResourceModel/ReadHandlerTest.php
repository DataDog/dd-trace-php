<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogRule\Test\Unit\Model\ResourceModel;

class ReadHandlerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogRule\Model\ResourceModel\ReadHandler
     */
    protected $subject;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $resourceMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $metadataMock;

    protected function setUp()
    {
        $this->resourceMock = $this->createMock(\Magento\CatalogRule\Model\ResourceModel\Rule::class);
        $this->metadataMock = $this->createMock(\Magento\Framework\EntityManager\MetadataPool::class);
        $this->subject = new \Magento\CatalogRule\Model\ResourceModel\ReadHandler(
            $this->resourceMock,
            $this->metadataMock
        );
    }

    public function testExecute()
    {
        $linkedField = 'entity_id';
        $entityId = 100;
        $entityType = \Magento\CatalogRule\Api\Data\RuleInterface::class;
        $entityData = [
            $linkedField => $entityId
        ];

        $customerGroupIds = [1, 2, 3];
        $websiteIds = [4, 5, 6];

        $metadataMock = $this->createPartialMock(
            \Magento\Framework\EntityManager\EntityMetadata::class,
            ['getLinkField']
        );
        $this->metadataMock->expects($this->once())
            ->method('getMetadata')
            ->with($entityType)
            ->willReturn($metadataMock);

        $metadataMock->expects($this->once())->method('getLinkField')->willReturn($linkedField);

        $this->resourceMock->expects($this->once())
            ->method('getCustomerGroupIds')
            ->with($entityId)
            ->willReturn($customerGroupIds);
        $this->resourceMock->expects($this->once())
            ->method('getWebsiteIds')
            ->with($entityId)
            ->willReturn($websiteIds);

        $expectedResult = [
            $linkedField => $entityId,
            'customer_group_ids' => $customerGroupIds,
            'website_ids' => $websiteIds
        ];

        $this->assertEquals($expectedResult, $this->subject->execute($entityType, $entityData));
    }
}
