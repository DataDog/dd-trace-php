<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Reports\Test\Unit\Block\Adminhtml\Sales\Grid\Column\Renderer;

use Magento\Backend\Block\Context;
use Magento\Backend\Block\Widget\Grid\Column;
use Magento\Framework\DataObject;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Reports\Block\Adminhtml\Sales\Grid\Column\Renderer\Date;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DateTest extends TestCase
{
    /**
     * @var Date
     */
    protected $date;

    /**
     * @var Context|MockObject
     */
    protected $contextMock;

    /**
     * @var ResolverInterface|MockObject
     */
    protected $resolverMock;

    /**
     * @var TimezoneInterface|MockObject
     */
    protected $localeDate;

    /**
     * @var string
     */
    private $globalStateLocaleBackup;

    /**
     * @var DateTimeFormatterInterface|MockObject
     */
    private $dateTimeFormatter;

    /**
     * @param string $locale
     */
    private function mockGridDateRendererBehaviorWithLocale($locale)
    {
        $this->resolverMock->expects($this->any())->method('getLocale')->willReturn($locale);
        $this->localeDate->expects($this->any())->method('getDateFormat')->willReturnCallback(
            function ($value) use ($locale) {
                return (new \IntlDateFormatter(
                    $locale,
                    $value,
                    \IntlDateFormatter::NONE
                ))->getPattern();
            }
        );
    }

    /**
     * @param string $objectDataIndex
     * @param string $periodType
     */
    private function mockGridDateColumnConfig($objectDataIndex, $periodType)
    {
        $columnMock = $this->getMockBuilder(Column::class)
            ->disableOriginalConstructor()
            ->setMethods(['getIndex', 'getPeriodType'])
            ->getMock();
        $columnMock->expects($this->once())->method('getIndex')->willReturn($objectDataIndex);
        $columnMock->expects($this->atLeastOnce())->method('getPeriodType')->willReturn($periodType);

        $this->date->setColumn($columnMock);
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->localeDate = $this->getMockBuilder(TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->localeDate
            ->expects($this->once())
            ->method('date')
            ->willReturnArgument(0);

        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock
            ->expects($this->once())
            ->method('getLocaleDate')
            ->willReturn($this->localeDate);

        $this->resolverMock = $this->getMockBuilder(ResolverInterface::class)
            ->getMock();

        $this->dateTimeFormatter = $this->createMock(
            DateTimeFormatterInterface::class
        );

        $objectManager = new ObjectManager($this);
        $this->date = $objectManager->getObject(
            Date::class,
            [
                'context' => $this->contextMock,
                'localeResolver' => $this->resolverMock,
                'dateTimeFormatter' => $this->dateTimeFormatter,
            ]
        );
        $this->globalStateLocaleBackup = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        $this->restoreTheDefaultLocaleGlobalState();
    }

    private function restoreTheDefaultLocaleGlobalState()
    {
        if (\Locale::getDefault() !== $this->globalStateLocaleBackup) {
            \Locale::setDefault($this->globalStateLocaleBackup);
        }
    }

    /**
     * @param string $data
     * @param string $locale
     * @param string $index
     * @param string $period
     * @param string $result
     * @dataProvider datesDataProvider
     * @return void
     */
    public function testRender($data, $locale, $index, $period, $result)
    {
        $this->mockGridDateRendererBehaviorWithLocale($locale);
        $this->mockGridDateColumnConfig($index, $period);

        $objectMock = $this->getMockBuilder(DataObject::class)
            ->setMethods(['getData'])
            ->getMock();
        $objectMock->expects($this->once())->method('getData')->willReturn($data);

        $this->dateTimeFormatter->expects($this->once())
            ->method('formatObject')
            ->with($this->isInstanceOf('DateTime'), $this->isType('string'), $locale)
            ->willReturn($result);

        $this->assertEquals($result, $this->date->render($objectMock));
    }

    /**
     * @return array
     */
    public function datesDataProvider()
    {
        return [
            [
                'data' => '2000',
                'locale' => 'en_US',
                'index' => 'period',
                'period' => 'year',
                'result' => '2000'
            ],
            [
                'data' => '2030',
                'locale' => 'en_US',
                'index' => 'period',
                'period' => 'year',
                'result' => '2030'
            ],
            [
                'data' => '2000-01',
                'locale' => 'en_US',
                'index' => 'period',
                'period' => 'month',
                'result' => '1/2000'
            ],
            [
                'data' => '2030-12',
                'locale' => 'en_US',
                'index' => 'period',
                'period' => 'month',
                'result' => '12/2030'
            ],
            [
                'data' => '2014-06-25',
                'locale' => 'en_US',
                'index' => 'period',
                'period' => 'day',
                'result' => 'Jun 25, 2014'
            ]
        ];
    }

    public function testDateIsRenderedIndependentOfSystemDefaultLocale()
    {
        $locale = 'en_US';
        $result = 'Jun 25, 2014';
        \Locale::setDefault('de_DE');
        $this->mockGridDateRendererBehaviorWithLocale($locale);
        $this->mockGridDateColumnConfig('period', 'day');

        $objectMock = $this->getMockBuilder(DataObject::class)
            ->setMethods(['getData'])
            ->getMock();
        $objectMock->expects($this->any())->method('getData')->willReturn('2014-06-25');

        $this->dateTimeFormatter->expects($this->once())
            ->method('formatObject')
            ->with($this->isInstanceOf('DateTime'), $this->isType('string'), $locale)
            ->willReturn($result);

        $this->assertEquals($result, $this->date->render($objectMock));
    }
}
