<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Indexer\Product\Flat\System\Config;

class ModeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Flat\System\Config\Mode
     */
    protected $model;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var \Magento\Indexer\Model\Indexer\State|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexerStateMock;

    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Flat\Processor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexerProcessorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->indexerStateMock = $this->createPartialMock(
            \Magento\Indexer\Model\Indexer\State::class,
            ['loadByIndexer', 'setStatus', 'save', '__wakeup']
        );
        $this->indexerProcessorMock = $this->createPartialMock(
            \Magento\Catalog\Model\Indexer\Product\Flat\Processor::class,
            ['getIndexer']
        );

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Catalog\Model\Indexer\Product\Flat\System\Config\Mode::class,
            [
                'config' => $this->configMock,
                'indexerState' => $this->indexerStateMock,
                'productFlatIndexerProcessor' => $this->indexerProcessorMock
            ]
        );
    }

    /**
     * @return array
     */
    public function dataProviderProcessValueEqual()
    {
        return [['0', '0'], ['', '0'], ['0', ''], ['1', '1']];
    }

    /**
     * @param string $oldValue
     * @param string $value
     * @dataProvider dataProviderProcessValueEqual
     */
    public function testProcessValueEqual($oldValue, $value)
    {
        $this->configMock->expects(
            $this->once()
        )->method(
            'getValue'
        )->with(
            null,
            'default'
        )->willReturn(
            $oldValue
        );

        $this->model->setValue($value);

        $this->indexerStateMock->expects($this->never())->method('loadByIndexer');
        $this->indexerStateMock->expects($this->never())->method('setStatus');
        $this->indexerStateMock->expects($this->never())->method('save');

        $this->indexerProcessorMock->expects($this->never())->method('getIndexer');

        $this->model->processValue();
    }

    /**
     * @return array
     */
    public function dataProviderProcessValueOn()
    {
        return [['0', '1'], ['', '1']];
    }

    /**
     * @param string $oldValue
     * @param string $value
     * @dataProvider dataProviderProcessValueOn
     */
    public function testProcessValueOn($oldValue, $value)
    {
        $this->configMock->expects(
            $this->once()
        )->method(
            'getValue'
        )->with(
            null,
            'default'
        )->willReturn(
            $oldValue
        );

        $this->model->setValue($value);

        $this->indexerStateMock->expects(
            $this->once()
        )->method(
            'loadByIndexer'
        )->with(
            'catalog_product_flat'
        )->willReturnSelf(
            
        );
        $this->indexerStateMock->expects(
            $this->once()
        )->method(
            'setStatus'
        )->with(
            'invalid'
        )->willReturnSelf(
            
        );
        $this->indexerStateMock->expects($this->once())->method('save')->willReturnSelf();

        $this->indexerProcessorMock->expects($this->never())->method('getIndexer');

        $this->model->processValue();
    }

    /**
     * @return array
     */
    public function dataProviderProcessValueOff()
    {
        return [['1', '0'], ['1', '']];
    }

    /**
     * @param string $oldValue
     * @param string $value
     * @dataProvider dataProviderProcessValueOff
     */
    public function testProcessValueOff($oldValue, $value)
    {
        $this->configMock->expects(
            $this->once()
        )->method(
            'getValue'
        )->with(
            null,
            'default'
        )->willReturn(
            $oldValue
        );

        $this->model->setValue($value);

        $this->indexerStateMock->expects($this->never())->method('loadByIndexer');
        $this->indexerStateMock->expects($this->never())->method('setStatus');
        $this->indexerStateMock->expects($this->never())->method('save');

        $indexerMock = $this->getMockForAbstractClass(
            \Magento\Framework\Indexer\IndexerInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['setScheduled', '__wakeup']
        );
        $indexerMock->expects($this->once())->method('setScheduled')->with(false);

        $this->indexerProcessorMock->expects(
            $this->once()
        )->method(
            'getIndexer'
        )->willReturn(
            $indexerMock
        );

        $this->model->processValue();
    }
}
