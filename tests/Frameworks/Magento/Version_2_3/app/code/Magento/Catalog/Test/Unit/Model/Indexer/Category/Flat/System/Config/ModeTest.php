<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Indexer\Category\Flat\System\Config;

class ModeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Indexer\Category\Flat\System\Config\Mode
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
     * @var \Magento\Framework\Indexer\IndexerRegistry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexerRegistry;

    /**
     * @var \Magento\Framework\Indexer\IndexerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $flatIndexer;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->indexerStateMock = $this->createPartialMock(
            \Magento\Indexer\Model\Indexer\State::class,
            ['loadByIndexer', 'setStatus', 'save', '__wakeup']
        );
        $this->indexerRegistry = $this->createPartialMock(
            \Magento\Framework\Indexer\IndexerRegistry::class,
            ['load', 'setScheduled', 'get']
        );

        $this->flatIndexer = $this->createMock(\Magento\Framework\Indexer\IndexerInterface::class);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Catalog\Model\Indexer\Category\Flat\System\Config\Mode::class,
            [
                'config' => $this->configMock,
                'indexerState' => $this->indexerStateMock,
                'indexerRegistry' => $this->indexerRegistry
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

        $this->indexerRegistry->expects($this->never())->method('load');
        $this->indexerRegistry->expects($this->never())->method('setScheduled');

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
            'catalog_category_flat'
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

        $this->indexerRegistry->expects($this->never())->method('load');
        $this->indexerRegistry->expects($this->never())->method('setScheduled');

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

        $this->indexerRegistry->expects($this->once())->method('get')->with('catalog_category_flat')
            ->willReturn($this->flatIndexer);
        $this->flatIndexer->expects($this->once())->method('setScheduled')->with(false);

        $this->model->processValue();
    }
}
