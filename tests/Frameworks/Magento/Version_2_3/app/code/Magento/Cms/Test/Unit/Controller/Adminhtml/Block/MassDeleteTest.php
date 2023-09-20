<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Test\Unit\Controller\Adminhtml\Block;

use Magento\Cms\Test\Unit\Controller\Adminhtml\AbstractMassActionTest;

class MassDeleteTest extends AbstractMassActionTest
{
    /**
     * @var \Magento\Cms\Controller\Adminhtml\Block\MassDelete
     */
    protected $massDeleteController;

    /**
     * @var \Magento\Cms\Model\ResourceModel\Block\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionFactoryMock;

    /**
     * @var \Magento\Cms\Model\ResourceModel\Block\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $blockCollectionMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionFactoryMock = $this->createPartialMock(
            \Magento\Cms\Model\ResourceModel\Block\CollectionFactory::class,
            ['create']
        );

        $this->blockCollectionMock =
            $this->createMock(\Magento\Cms\Model\ResourceModel\Block\Collection::class);

        $this->massDeleteController = $this->objectManager->getObject(
            \Magento\Cms\Controller\Adminhtml\Block\MassDelete::class,
            [
                'context' => $this->contextMock,
                'filter' => $this->filterMock,
                'collectionFactory' => $this->collectionFactoryMock
            ]
        );
    }

    public function testMassDeleteAction()
    {
        $deletedBlocksCount = 2;

        $collection = [
            $this->getBlockMock(),
            $this->getBlockMock()
        ];

        $this->collectionFactoryMock->expects($this->once())->method('create')->willReturn($this->blockCollectionMock);

        $this->filterMock->expects($this->once())
            ->method('getCollection')
            ->with($this->blockCollectionMock)
            ->willReturn($this->blockCollectionMock);

        $this->blockCollectionMock->expects($this->once())->method('getSize')->willReturn($deletedBlocksCount);
        $this->blockCollectionMock->expects($this->once())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator($collection));

        $this->messageManagerMock->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('A total of %1 record(s) have been deleted.', $deletedBlocksCount));
        $this->messageManagerMock->expects($this->never())->method('addErrorMessage');

        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('*/*/')
            ->willReturnSelf();

        $this->assertSame($this->resultRedirectMock, $this->massDeleteController->execute());
    }

    /**
     * Create Cms Block Collection Mock
     *
     * @return \Magento\Cms\Model\ResourceModel\Block\Collection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function getBlockMock()
    {
        $blockMock = $this->createPartialMock(\Magento\Cms\Model\ResourceModel\Block\Collection::class, ['delete']);
        $blockMock->expects($this->once())->method('delete')->willReturn(true);

        return $blockMock;
    }
}
