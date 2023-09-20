<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Test\Unit\Component\Filters\Type;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\Filters\Type\Select;

/**
 * Class SelectTest
 */
class SelectTest extends \PHPUnit\Framework\TestCase
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
        $this->uiComponentFactory = $this->createPartialMock(
            \Magento\Framework\View\Element\UiComponentFactory::class,
            ['create']
        );
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
        $date = new Select(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            null,
            []
        );

        $this->assertTrue($date->getComponentName() === Select::NAME);
    }

    /**
     * Run test prepare method
     *
     * @param array $data
     * @param array $filterData
     * @param array|null $expectedCondition
     * @dataProvider getPrepareDataProvider
     * @return void
     */
    public function testPrepare($data, $filterData, $expectedCondition)
    {
        $processor = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        $name = $data['name'];
        /** @var UiComponentInterface $uiComponent */
        $uiComponent = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponentInterface::class,
            [],
            '',
            false
        );

        $uiComponent->expects($this->any())
            ->method('getContext')
            ->willReturn($this->contextMock);

        $this->contextMock->expects($this->any())
            ->method('getNamespace')
            ->willReturn(Select::NAME);
        $this->contextMock->expects($this->any())
            ->method('addComponentDefinition')
            ->with(Select::NAME, ['extends' => Select::NAME]);
        $this->contextMock->expects($this->any())
            ->method('getFiltersParams')
            ->willReturn($filterData);
        /** @var DataProviderInterface $dataProvider */
        $dataProvider = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface::class,
            ['addFilter'],
            '',
            false
        );
        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProvider);

        if ($expectedCondition !== null) {
            $filterMock = $this->createMock(\Magento\Framework\Api\Filter::class);
            $this->filterBuilderMock->expects($this->any())
                ->method('setConditionType')
                ->with($expectedCondition)
                ->willReturnSelf();
            $this->filterBuilderMock->expects($this->any())
                ->method('setField')
                ->with($name)
                ->willReturnSelf();
            $this->filterBuilderMock->expects($this->any())
                ->method('setValue')
                ->willReturnSelf();
            $this->filterBuilderMock->expects($this->any())
                ->method('create')
                ->willReturn($filterMock);
            $dataProvider->expects($this->any())
                ->method('addFilter')
                ->with($filterMock);
        }

        /** @var \Magento\Framework\Data\OptionSourceInterface $selectOptions */
        $selectOptions = $this->getMockForAbstractClass(
            \Magento\Framework\Data\OptionSourceInterface::class,
            [],
            '',
            false
        );

        $this->uiComponentFactory->expects($this->any())
            ->method('create')
            ->with($name, Select::COMPONENT, ['context' => $this->contextMock, 'options' => $selectOptions])
            ->willReturn($uiComponent);

        $date = new Select(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            $selectOptions,
            [],
            $data
        );

        $date->prepare();
    }

    /**
     * @return array
     */
    public function getPrepareDataProvider()
    {
        return [
            [
                ['name' => 'test_date', 'config' => []],
                [],
                null
            ],
            [
                ['name' => 'test_date', 'config' => []],
                ['test_date' => ''],
                'eq'
            ],
            [
                ['name' => 'test_date', 'config' => ['dataType' => 'text']],
                ['test_date' => 'some_value'],
                'eq'
            ],
            [
                ['name' => 'test_date', 'config' => ['dataType' => 'select']],
                ['test_date' => ['some_value1', 'some_value2']],
                'in'
            ],
            [
                ['name' => 'test_date', 'config' => ['dataType' => 'multiselect']],
                ['test_date' => 'some_value'],
                'finset'
            ],
        ];
    }
}
