<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\Test\Unit\Component\Filters\Type;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface as UiContext;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Filters\Type\DateRange;
use Magento\Ui\Component\Form\Element\DataType\Date as FormDate;

/**
 * Class DateRangeTest
 */
class DateRangeTest extends \PHPUnit\Framework\TestCase
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
        $dateRange = new DateRange(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            []
        );
        $this->assertTrue($dateRange->getComponentName() === DateRange::NAME);
    }

    /**
     * Run test prepare method
     *
     * @param string $name
     * @param array $filterData
     * @param array|null $expectedCondition
     * @dataProvider getPrepareDataProvider
     * @return void
     */
    public function testPrepare($name, $filterData, $expectedCondition)
    {
        $processor = $this->getMockBuilder(\Magento\Framework\View\Element\UiComponent\Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        /** @var FormDate $uiComponent */
        $uiComponent = $this->createMock(\Magento\Ui\Component\Form\Element\DataType\Date::class);

        $uiComponent->expects($this->any())
            ->method('getContext')
            ->willReturn($this->contextMock);

        $this->contextMock->expects($this->any())
            ->method('getNamespace')
            ->willReturn(DateRange::NAME);
        $this->contextMock->expects($this->any())
            ->method('addComponentDefinition')
            ->with(DateRange::NAME, ['extends' => DateRange::NAME]);
        $this->contextMock->expects($this->any())
            ->method('getRequestParam')
            ->with(UiContext::FILTER_VAR)
            ->willReturn($filterData);
        $dataProvider = $this->getMockForAbstractClass(
            \Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface::class,
            [],
            '',
            false
        );
        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProvider);

        if ($expectedCondition !== null) {
            /** @var DataProviderInterface $dataProvider */
            $dataProvider->expects($this->any())
                ->method('addFilter')
                ->with($expectedCondition, $name);

            $uiComponent->expects($this->any())
                ->method('getLocale')
                ->willReturn($expectedCondition['locale']);
            $uiComponent->expects($this->any())
                ->method('convertDate')
                ->willReturnArgument(0);
        }

        $this->uiComponentFactory->expects($this->any())
            ->method('create')
            ->with($name, DateRange::COMPONENT, ['context' => $this->contextMock])
            ->willReturn($uiComponent);

        $dateRange = new DateRange(
            $this->contextMock,
            $this->uiComponentFactory,
            $this->filterBuilderMock,
            $this->filterModifierMock,
            [],
            ['name' => $name]
        );

        $dateRange->prepare();
    }

    /**
     * @return array
     */
    public function getPrepareDataProvider()
    {
        return [
            [
                'test_date',
                ['test_date' => ['from' => '11-05-2015', 'to' => '']],
                ['from' => '11-05-2015', 'orig_from' => '11-05-2015', 'datetime' => true, 'locale' => 'en_US'],
            ],
            [
                'test_date',
                ['test_date' => ['from' => '', 'to' => '11-05-2015']],
                ['to' => '11-05-2015', 'orig_to' => '11-05-2015', 'datetime' => true, 'locale' => 'en_US'],
            ],
            [
                'test_date',
                ['test_date' => ['from' => '10-05-2015', 'to' => '11-05-2015']],
                [
                    'from' => '10-05-2015',
                    'orig_from' => '10-05-2015',
                    'to' => '11-05-2015',
                    'orig_to' => '11-05-2015',
                    'datetime' => true,
                    'locale' => 'en_US'
                ],
            ],
            [
                'test_date',
                ['test_date' => ['from' => '', 'to' => '']],
                null,
            ],
        ];
    }
}
