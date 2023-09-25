<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Framework\View\Layout\Element
 */
namespace Magento\Framework\View\Test\Unit\Layout;

use \Magento\Framework\View\Layout\GeneratorPool;
use \Magento\Framework\View\Layout\ScheduledStructure;
use \Magento\Framework\View\Layout\Data\Structure as DataStructure;

class GeneratorPoolTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Layout\ScheduledStructure\Helper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $helperMock;

    /**
     * @var \Magento\Framework\View\Layout\Reader\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $readerContextMock;

    /**
     * @var \Magento\Framework\View\Layout\Generator\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $generatorContextMock;

    /**
     * @var ScheduledStructure
     */
    protected $scheduledStructure;

    /**
     * @var \Magento\Framework\View\Layout\Data\Structure|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $structureMock;

    /**
     * @var GeneratorPool
     */
    protected $model;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // ScheduledStructure
        $this->readerContextMock = $this->getMockBuilder(\Magento\Framework\View\Layout\Reader\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scheduledStructure = new ScheduledStructure();
        $this->readerContextMock->expects($this->any())->method('getScheduledStructure')
            ->willReturn($this->scheduledStructure);

        // DataStructure
        $this->generatorContextMock = $this->getMockBuilder(\Magento\Framework\View\Layout\Generator\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->structureMock = $this->getMockBuilder(\Magento\Framework\View\Layout\Data\Structure::class)
            ->disableOriginalConstructor()
            ->setMethods(['reorderChildElement'])
            ->getMock();
        $this->generatorContextMock->expects($this->any())->method('getStructure')
            ->willReturn($this->structureMock);

        $this->helperMock = $this->getMockBuilder(\Magento\Framework\View\Layout\ScheduledStructure\Helper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $helperObjectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $helperObjectManager->getObject(
            \Magento\Framework\View\Layout\GeneratorPool::class,
            [
                'helper' => $this->helperMock,
                'generators' => $this->getGeneratorsMocks()
            ]
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject[]
     */
    protected function getGeneratorsMocks()
    {
        $firstGenerator = $this->createMock(\Magento\Framework\View\Layout\GeneratorInterface::class);
        $firstGenerator->expects($this->any())->method('getType')->willReturn('first_generator');
        $firstGenerator->expects($this->atLeastOnce())->method('process');

        $secondGenerator = $this->createMock(\Magento\Framework\View\Layout\GeneratorInterface::class);
        $secondGenerator->expects($this->any())->method('getType')->willReturn('second_generator');
        $secondGenerator->expects($this->atLeastOnce())->method('process');
        return [$firstGenerator, $secondGenerator];
    }

    /**
     * @param array $schedule
     * @param array $expectedSchedule
     * @return void
     * @dataProvider processDataProvider
     */
    public function testProcess($schedule, $expectedSchedule)
    {
        foreach ($schedule['structure'] as $structureElement) {
            $this->scheduledStructure->setStructureElement($structureElement, []);
        }

        $reorderMap = [];
        foreach ($schedule['sort'] as $elementName => $sort) {
            list($parentName, $sibling, $isAfter) = $sort;
            $this->scheduledStructure->setElementToSortList($parentName, $elementName, $sibling, $isAfter);
            $reorderMap[] = [$parentName, $elementName, $sibling, $isAfter];
        }
        foreach ($schedule['move'] as $elementName => $move) {
            $this->scheduledStructure->setElementToMove($elementName, $move);
            list($destination, $sibling, $isAfter) = $move;
            $reorderMap[] = [$destination, $elementName, $sibling, $isAfter];
        }
        $invocation = $this->structureMock->expects($this->any())->method('reorderChildElement');
        call_user_func_array([$invocation, 'withConsecutive'], $reorderMap);

        foreach ($schedule['remove'] as $remove) {
            $this->scheduledStructure->setElementToRemoveList($remove);
        }

        $this->helperMock->expects($this->atLeastOnce())->method('scheduleElement')
            ->with($this->scheduledStructure, $this->structureMock, $this->anything())
            ->willReturnCallback(function ($scheduledStructure, $structure, $elementName) use ($schedule) {
                /**
                 * @var $scheduledStructure ScheduledStructure
                 * @var $structure DataStructure
                 */
                $this->assertContains($elementName, $schedule['structure']);
                $scheduledStructure->unsetStructureElement($elementName);
                $scheduledStructure->setElement($elementName, ['block', []]);
                $structure->createStructuralElement($elementName, 'block', 'someClass');
            });

        $this->model->process($this->readerContextMock, $this->generatorContextMock);
        $this->assertEquals($expectedSchedule, $this->scheduledStructure->getElements());
    }

    /**
     * Data provider fo testProcess
     *
     * @return array
     */
    public function processDataProvider()
    {
        return [
            [
                'schedule' => [
                    'structure' => [
                        'first.element',
                        'second.element',
                        'third.element',
                        'remove.element',
                        'sort.element',
                    ],
                    'move' => [
                        'third.element' => ['second.element', 'sibling', false, 'alias'],
                    ],
                    'remove' => ['remove.element'],
                    'sort' => [
                        'sort.element' => ['second.element', 'sibling', false, 'alias'],
                    ],
                ],
                'expectedScheduledElements' => [
                    'first.element' => ['block', []],
                    'second.element' => ['block', []],
                    'third.element' => ['block', []],
                    'sort.element' => ['block', []],
                ],
            ],
        ];
    }
}
