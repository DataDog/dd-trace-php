<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Test\Unit\Model\Config;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\State\InvalidTransitionException;
use Magento\Store\Model\Config\Importer;
use Magento\Store\Model\Config\Importer\DataDifferenceCalculator;
use Magento\Store\Model\Config\Importer\Processor\ProcessorFactory;
use Magento\Store\Model\Config\Importer\Processor\ProcessorInterface;
use Magento\Store\Model\ResourceModel\Website;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject as Mock;
use PHPUnit\Framework\TestCase;

/**
 * Test for Importer.
 *
 * @see Importer
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ImporterTest extends TestCase
{
    /**
     * @var Importer
     */
    private $model;

    /**
     * @var Importer\DataDifferenceCalculator|Mock
     */
    private $dataDifferenceCalculatorMock;

    /**
     * @var Importer\Processor\ProcessorFactory|Mock
     */
    private $processorFactoryMock;

    /**
     * @var Importer\Processor\ProcessorInterface|Mock
     */
    private $processorMock;

    /**
     * @var StoreManager|Mock
     */
    private $storeManagerMock;

    /**
     * @var CacheInterface|Mock
     */
    private $cacheManagerMock;

    /**
     * @var Website|Mock
     */
    private $resourceMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->dataDifferenceCalculatorMock = $this->getMockBuilder(DataDifferenceCalculator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->processorFactoryMock = $this->getMockBuilder(ProcessorFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->processorMock = $this->getMockBuilder(ProcessorInterface::class)
            ->getMockForAbstractClass();
        $this->storeManagerMock = $this->getMockBuilder(StoreManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->cacheManagerMock = $this->getMockBuilder(CacheInterface::class)
            ->getMockForAbstractClass();
        $this->resourceMock = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Importer(
            $this->dataDifferenceCalculatorMock,
            $this->processorFactoryMock,
            $this->storeManagerMock,
            $this->cacheManagerMock,
            $this->resourceMock
        );
    }

    public function testImport()
    {
        $data = [
            ScopeInterface::SCOPE_STORES => ['stores'],
            ScopeInterface::SCOPE_GROUPS => ['groups'],
            ScopeInterface::SCOPE_WEBSITES => ['websites'],
        ];

        $createProcessorMock = clone $this->processorMock;
        $deleteProcessorMock = clone $this->processorMock;
        $updateProcessorMock = clone $this->processorMock;

        $this->processorFactoryMock->expects($this->exactly(3))
            ->method('create')
            ->withConsecutive(
                [ProcessorFactory::TYPE_CREATE],
                [ProcessorFactory::TYPE_DELETE],
                [ProcessorFactory::TYPE_UPDATE]
            )->willReturnOnConsecutiveCalls(
                $createProcessorMock,
                $deleteProcessorMock,
                $updateProcessorMock
            );
        $this->resourceMock->expects($this->once())
            ->method('beginTransaction');
        $createProcessorMock->expects($this->once())
            ->method('run')
            ->with($data);
        $deleteProcessorMock->expects($this->once())
            ->method('run')
            ->with($data);
        $updateProcessorMock->expects($this->once())
            ->method('run')
            ->with($data);
        $this->resourceMock->expects($this->once())
            ->method('commit');
        $this->storeManagerMock->expects($this->exactly(2))
            ->method('reinitStores');
        $this->cacheManagerMock->expects($this->exactly(2))
            ->method('clean');
        $this->dataDifferenceCalculatorMock->expects($this->once())
            ->method('getItemsToCreate')
            ->willReturnMap([
                [ScopeInterface::SCOPE_STORES, ['stores'], [['name' => '3 stores']]],
                [ScopeInterface::SCOPE_GROUPS, ['groups'], [['name' => '2 groups'], ['name' => '3 groups']]],
                [ScopeInterface::SCOPE_WEBSITES, ['websites'], [['name' => '1 website']]],
            ]);

        $this->assertSame(
            [
                'Stores were processed',
                'The following new store groups must be associated with a root category: 2 groups, 3 groups. '
                . PHP_EOL
                . 'Associate a store group with a root category in the Admin Panel: Stores > Settings > All Stores.'
            ],
            $this->model->import($data)
        );
    }

    public function testImportWithException()
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Some error');
        $this->processorFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->processorMock);
        $this->resourceMock->expects($this->once())
            ->method('beginTransaction');
        $this->processorMock->expects($this->any())
            ->method('run')
            ->willThrowException(new \Exception('Some error'));
        $this->resourceMock->expects($this->never())
            ->method('commit');
        $this->storeManagerMock->expects($this->exactly(2))
            ->method('reinitStores');
        $this->cacheManagerMock->expects($this->exactly(2))
            ->method('clean');

        $this->model->import([]);
    }

    public function testGetWarningMessages()
    {
        $expectedData = [
            'These Stores will be deleted: 3 stores',
            'These Groups will be deleted: 2 groups',
            'These Websites will be deleted: 1 website',
            'These Websites will be updated: 7 websites',
        ];
        $data = [
            ScopeInterface::SCOPE_STORES => ['stores'],
            ScopeInterface::SCOPE_GROUPS => ['groups'],
            ScopeInterface::SCOPE_WEBSITES => ['websites'],
        ];

        $this->dataDifferenceCalculatorMock->expects($this->exactly(3))
            ->method('getItemsToDelete')
            ->willReturnMap([
                [ScopeInterface::SCOPE_STORES, ['stores'], [['name' => '3 stores']]],
                [ScopeInterface::SCOPE_GROUPS, ['groups'], [['name' => '2 groups']]],
                [ScopeInterface::SCOPE_WEBSITES, ['websites'], [['name' => '1 website']]],
            ]);
        $this->dataDifferenceCalculatorMock->expects($this->exactly(3))
            ->method('getItemsToUpdate')
            ->willReturnMap([
                [ScopeInterface::SCOPE_WEBSITES, ['websites'], [['name' => '7 websites']]],
            ]);

        $this->assertSame($expectedData, $this->model->getWarningMessages($data));
    }
}
