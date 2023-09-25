<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Analytics\Test\Unit\ReportXml;

use Magento\Analytics\ReportXml\Query;
use Magento\Analytics\ReportXml\SelectHydrator as selectHydrator;
use Magento\Framework\DB\Select;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    /**
     * @var Select|MockObject
     */
    private $selectMock;

    /**
     * @var selectHydrator|MockObject
     */
    private $selectHydratorMock;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var Query
     */
    private $query;

    /**
     * @var string
     */
    private $connectionName = 'test_connection';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->selectMock = $this->createMock(Select::class);

        $this->selectHydratorMock = $this->createMock(selectHydrator::class);

        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->query = $this->objectManagerHelper->getObject(
            Query::class,
            [
                'select' => $this->selectMock,
                'connectionName' => $this->connectionName,
                'selectHydrator' => $this->selectHydratorMock,
                'config' => []
            ]
        );
    }

    /**
     * @return void
     */
    public function testJsonSerialize()
    {
        $selectParts = ['part' => 1];

        $this->selectHydratorMock
            ->expects($this->once())
            ->method('extract')
            ->with($this->selectMock)
            ->willReturn($selectParts);

        $expectedResult = [
            'connectionName' => $this->connectionName,
            'select_parts' => $selectParts,
            'config' => []
        ];

        $this->assertSame($expectedResult, $this->query->jsonSerialize());
    }
}
