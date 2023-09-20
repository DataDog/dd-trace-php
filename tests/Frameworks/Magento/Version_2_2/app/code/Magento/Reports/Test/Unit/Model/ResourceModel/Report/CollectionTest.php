<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Reports\Test\Unit\Model\ResourceModel\Report;

use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Report\Collection;
use Magento\Reports\Model\ResourceModel\Report\Collection\Factory as ReportCollectionFactory;

/**
 * Class CollectionTest
 *
 * @covers \Magento\Reports\Model\ResourceModel\Report\Collection
 */
class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var EntityFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $entityFactoryMock;

    /**
     * @var TimezoneInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $timezoneMock;

    /**
     * @var ReportCollectionFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $factoryMock;

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);
        $this->timezoneMock = $this->createMock(TimezoneInterface::class);
        $this->factoryMock = $this->createMock(ReportCollectionFactory::class);

        $this->timezoneMock->method('formatDate')
            ->will($this->returnCallback([$this, 'formatDate']));

        $this->collection = new Collection(
            $this->entityFactoryMock,
            $this->timezoneMock,
            $this->factoryMock
        );
    }

    /**
     * @return void
     */
    public function testGetPeriods()
    {
        $expectedArray = ['day' => 'Day', 'month' => 'Month', 'year' => 'Year'];
        $this->assertEquals($expectedArray, $this->collection->getPeriods());
    }

    /**
     * @return void
     */
    public function testGetStoreIds()
    {
        $storeIds = [1];
        $this->assertEquals(null, $this->collection->getStoreIds());
        $this->collection->setStoreIds($storeIds);
        $this->assertEquals($storeIds, $this->collection->getStoreIds());
    }

    /**
     * @param string $period
     * @param \DateTimeInterface $fromDate
     * @param \DateTimeInterface $toDate
     * @param int $size
     * @dataProvider intervalsDataProvider
     * @return void
     */
    public function testGetSize($period, $fromDate, $toDate, $size)
    {
        $this->collection->setPeriod($period);
        $this->collection->setInterval($fromDate, $toDate);
        $this->assertEquals($size, $this->collection->getSize());
    }

    /**
     * @return void
     */
    public function testGetPageSize()
    {
        $pageSize = 1;
        $this->assertEquals(null, $this->collection->getPageSize());
        $this->collection->setPageSize($pageSize);
        $this->assertEquals($pageSize, $this->collection->getPageSize());
    }

    /**
     * @param string $period
     * @param \DateTimeInterface $fromDate
     * @param \DateTimeInterface $toDate
     * @param int $size
     * @dataProvider intervalsDataProvider
     * @return void
     */
    public function testGetReports($period, $fromDate, $toDate, $size)
    {
        $this->collection->setPeriod($period);
        $this->collection->setInterval($fromDate, $toDate);
        $reports = $this->collection->getReports();
        foreach ($reports as $report) {
            $this->assertInstanceOf(\Magento\Framework\DataObject::class, $report);
            $reportData = $report->getData();
            $this->assertTrue(empty($reportData['children']));
            $this->assertTrue($reportData['is_empty']);
        }
        $this->assertEquals($size, count($reports));
    }

    /**
     * @return void
     */
    public function testLoadData()
    {
        $this->assertInstanceOf(
            Collection::class,
            $this->collection->loadData()
        );
    }

    /**
     * @return array
     */
    public function intervalsDataProvider()
    {
        return [
            [
                '_period' => 'day',
                '_from' => new \DateTime('-3 day'),
                '_to' => new \DateTime('+3 day'),
                'size' => 7
            ],
            [
                '_period' => 'month',
                '_from' => new \DateTime('2015-01-15 11:11:11'),
                '_to' => new \DateTime('2015-01-25 11:11:11'),
                'size' => 1
            ],
            [
                '_period' => 'month',
                '_from' => new \DateTime('2015-01-15 11:11:11'),
                '_to' => new \DateTime('2015-02-25 11:11:11'),
                'size' => 2
            ],
            [
                '_period' => 'year',
                '_from' => new \DateTime('2015-01-15 11:11:11'),
                '_to' => new \DateTime('2015-01-25 11:11:11'),
                'size' => 1
            ],
            [
                '_period' => 'year',
                '_from' => new \DateTime('2014-01-15 11:11:11'),
                '_to' => new \DateTime('2015-01-25 11:11:11'),
                'size' => 2
            ],
            [
                '_period' => null,
                '_from' => new \DateTime('-3 day'),
                '_to' => new \DateTime('+3 day'),
                'size' => 0
            ]
        ];
    }

    /**
     * @param \DateTimeInterface $dateStart
     * @return string
     */
    public function formatDate(\DateTimeInterface $dateStart): string
    {
        $formatter = new \IntlDateFormatter(
            "en_US",
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::SHORT,
            new \DateTimeZone('America/Los_Angeles')
        );

        return $formatter->format($dateStart);
    }
}
