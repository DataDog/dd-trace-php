<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Test\Unit\Model\ResourceModel\Attribute;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\ConfigurableProduct\Model\ResourceModel\Attribute\OptionProvider;
use Magento\Framework\EntityManager\EntityMetadataInterface;

class OptionProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var OptionProvider
     */
    private $model;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var MetadataPool|\PHPUnit\Framework\MockObject\MockObject
     */
    private $metadataPool;

    /**
     * @var EntityMetadataInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $entityManager;

    protected function setUp(): void
    {
        $this->metadataPool = $this->getMockBuilder(MetadataPool::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->entityManager = $this->getMockBuilder(EntityMetadataInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $this->objectManagerHelper->getObject(
            OptionProvider::class,
            [
                'metadataPool' => $this->metadataPool
            ]
        );
    }

    public function testGetProductEntityLinkField()
    {
        $linkField = 'link_text';
        $this->metadataPool->expects($this->once())
            ->method('getMetadata')
            ->willReturn($this->entityManager);
        $this->entityManager->expects($this->once())
            ->method('getLinkField')
            ->willReturn($linkField);

        $this->assertEquals($linkField, $this->model->getProductEntityLinkField());
    }
}
