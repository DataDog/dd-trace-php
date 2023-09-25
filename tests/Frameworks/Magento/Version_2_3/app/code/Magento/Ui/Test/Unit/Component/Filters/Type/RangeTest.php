<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Test\Unit\Component\Filters\Type;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Filters\Type\Range;

/**
 * Class RangeTest
 */
class RangeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ContextInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var UiComponentFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $uiComponentFactory;

    /**
     * @var \Magento\Framework\Api\FilterBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filterBuilderMock;

    /**
     * @var \Magento\Ui\Component\Filters\FilterModifier|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $filterModifierMock;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->contextMock = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponent\ContextInterface::class,
            [],
            '',
            false
        );
        $this->uiComponentFactory = $this->createMock(\Magento\Framework\View\Element\UiComponentFactory::class);
        $this->filterBuilderMock = $this->createMock(\Magento\Framework\Api\FilterBuilder::class);
        $this->filterModifierMock = $this->createPartialMock(
            \Magento\Ui\Component\Filters\FilterModifier::class,
            ['applyFilterModifier']
        );
    }

    /**
     * Run test getComponentName method
     *
     * @return void
     */
    public function testGetComponentName()
    {
        $this->contextMock->expects($this->never())->method('getProcessor');
        $range = new Range(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            []
        );

        $this->assertTrue($range->getComponentName() === Range::NAME);
    }

    /**
     * Run test prepare method
     *
     * @param string $name
     * @param array $filterData
     * @param array|null $expectedCalls
     * @dataProvider getPrepareDataProvider
     * @return void
     */
    public function testPrepare($name, $filterData, $expectedCalls)
    {
        $processor = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        $filter = $this->createMock(\Magento\Framework\Api\Filter::class);
        $this->filterBuilderMock->expects($this->any())
            ->method('setConditionType')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('setField')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('setValue')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('create')
            ->willReturn($filter);

        $this->contextMock->expects($this->any())
            ->method('getNamespace')
            ->willReturn(Range::NAME);
        $this->contextMock->expects($this->any())
            ->method('addComponentDefinition')
            ->with(Range::NAME, ['extends' => Range::NAME]);
        $this->contextMock->expects($this->any())
            ->method('getFiltersParams')
            ->willReturn($filterData);

        /** @var DataProviderInterface $dataProvider */
        $dataProvider = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface::class,
            [],
            '',
            false
        );

        $this->contextMock->expects($this->atLeastOnce())
            ->method('getDataProvider')
            ->willReturn($dataProvider);

        $dataProvider->expects($this->exactly($expectedCalls))
            ->method('addFilter')
            ->with($filter);

        $range = new Range(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            [],
            ['name' => $name]
        );
        $range->prepare();
    }

    /**
     * @return array
     */
    public function getPrepareDataProvider()
    {
        return [
            [
                'test_date',
                ['test_date' => ['from' => 0, 'to' => 1]],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => '', 'to' => 2]],
                1
            ],
            [
                'test_date',
                ['test_date' => ['from' => 1, 'to' => '']],
                1
            ],
            [
                'test_date',
                ['test_date' => ['from' => 1, 'to' => 0]],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => 1, 'to' => 2]],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => 0, 'to' => 0]],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => '0', 'to' => '0']],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => '0.0', 'to' => 1]],
                2
            ],
            [
                'test_date',
                ['test_date' => ['from' => '', 'to' => '']],
                0
            ],
            [
                'test_date',
                ['test_date' => ['from' => 'a', 'to' => 'b']],
                0
            ],
            [
                'test_date',
                ['test_date' => ['from' => '1']],
                1
            ],
            [
                'test_date',
                ['test_date' => ['to' => '1']],
                1
            ],
            [
                'test_date',
                ['test_date' => []],
                0
            ],
        ];
    }
}
