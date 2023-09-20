<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DB\Test\Unit;

use Magento\Framework\DB\Select;

/**
 * Class AbstractMapperTest
 */
class AbstractMapperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Model\ResourceModel\Db\AbstractDb|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionMock;

    /**
     * @var \Magento\Framework\DB\Select|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\Data\Collection\Db\FetchStrategyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fetchStrategyMock;

    /**
     * @var \Magento\Framework\Data\ObjectFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectFactoryMock;

    /**
     * @var \Magento\Framework\DB\MapperFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $mapperFactoryMock;

    /**
     * @var \Magento\Framework\DB\AbstractMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $mapper;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->resourceMock = $this->getMockForAbstractClass(
            \Magento\Framework\Model\ResourceModel\Db\AbstractDb::class,
            [],
            '',
            false,
            true,
            true,
            []
        );
        $this->connectionMock = $this->getMockForAbstractClass(
            \Magento\Framework\DB\Adapter\AdapterInterface::class,
            [],
            '',
            false,
            true,
            true,
            []
        );
        $this->selectMock = $this->createMock(\Magento\Framework\DB\Select::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->fetchStrategyMock = $this->getMockForAbstractClass(
            \Magento\Framework\Data\Collection\Db\FetchStrategyInterface::class,
            [],
            '',
            false,
            true,
            true,
            []
        );
        $this->objectFactoryMock = $this->createMock(\Magento\Framework\Data\ObjectFactory::class);
        $this->mapperFactoryMock = $this->createMock(\Magento\Framework\DB\MapperFactory::class);
    }

    /**
     * Run test map method
     *
     * @param array $mapperMethods
     * @param array $criteriaParts
     * @return void
     *
     * @dataProvider dataProviderMap
     */
    public function testMap(array $mapperMethods, array $criteriaParts)
    {
        /** @var \Magento\Framework\DB\AbstractMapper|\PHPUnit\Framework\MockObject\MockObject $mapper */
        $mapper = $this->getMockForAbstractClass(
            \Magento\Framework\DB\AbstractMapper::class,
            [
                'logger' => $this->loggerMock,
                'fetchStrategy' => $this->fetchStrategyMock,
                'objectFactory' => $this->objectFactoryMock,
                'mapperFactory' => $this->mapperFactoryMock,
                'select' => $this->selectMock
            ],
            '',
            true,
            true,
            true,
            $mapperMethods
        );
        $criteriaMock = $this->getMockForAbstractClass(
            \Magento\Framework\Api\CriteriaInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['toArray']
        );
        $criteriaMock->expects($this->once())
            ->method('toArray')
            ->willReturn($criteriaParts);
        foreach ($mapperMethods as $value => $method) {
            $mapper->expects($this->once())
                ->method($method)
                ->with($value);
        }

        $this->assertEquals($this->selectMock, $mapper->map($criteriaMock));
    }

    public function testMapException()
    {
        $mapperMethods = [
            'my-test-value1' => 'mapMyMapperMethodOne'
        ];

        $criteriaParts = [
            'my_mapper_method_one' => 'my-test-value1'
        ];
        /** @var \Magento\Framework\DB\AbstractMapper|\PHPUnit\Framework\MockObject\MockObject $mapper */
        $mapper = $this->getMockForAbstractClass(
            \Magento\Framework\DB\AbstractMapper::class,
            [
                'logger' => $this->loggerMock,
                'fetchStrategy' => $this->fetchStrategyMock,
                'objectFactory' => $this->objectFactoryMock,
                'mapperFactory' => $this->mapperFactoryMock,
                'select' => $this->selectMock
            ],
            '',
            true,
            true,
            true,
            $mapperMethods
        );
        $criteriaMock = $this->getMockForAbstractClass(
            \Magento\Framework\Api\CriteriaInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['toArray']
        );
        $criteriaMock->expects($this->once())
            ->method('toArray')
            ->willReturn($criteriaParts);
        $this->expectException(\InvalidArgumentException::class);
        $mapper->map($criteriaMock);
    }

    /**
     * Run test addExpressionFieldToSelect method
     *
     * @return void
     */
    public function testAddExpressionFieldToSelect()
    {
        $fields = [
            'key-attribute' => 'value-attribute',
        ];
        /** @var \Magento\Framework\DB\AbstractMapper|\PHPUnit\Framework\MockObject\MockObject $mapper */
        $mapper = $this->getMockForAbstractClass(
            \Magento\Framework\DB\AbstractMapper::class,
            [
                'logger' => $this->loggerMock,
                'fetchStrategy' => $this->fetchStrategyMock,
                'objectFactory' => $this->objectFactoryMock,
                'mapperFactory' => $this->mapperFactoryMock,
                'select' => $this->selectMock
            ],
            '',
            true,
            true,
            true,
            []
        );

        $this->selectMock->expects($this->once())
            ->method('columns')
            ->with(['my-alias' => "('sub_total', 'SUM(value-attribute)', 'revenue')"]);

        $mapper->addExpressionFieldToSelect('my-alias', "('sub_total', 'SUM({{key-attribute}})', 'revenue')", $fields);
    }

    /**
     * Run test addExpressionFieldToSelect method
     *
     * @param mixed $field
     * @param mixed $condition
     * @return void
     *
     * @dataProvider dataProviderAddFieldToFilter
     */
    public function testAddFieldToFilter($field, $condition)
    {
        $resultCondition = 'sql-condition-value';

        /** @var \Magento\Framework\DB\AbstractMapper|\PHPUnit\Framework\MockObject\MockObject $mapper */
        $mapper = $this->getMockForAbstractClass(
            \Magento\Framework\DB\AbstractMapper::class,
            [
                'logger' => $this->loggerMock,
                'fetchStrategy' => $this->fetchStrategyMock,
                'objectFactory' => $this->objectFactoryMock,
                'mapperFactory' => $this->mapperFactoryMock,
                'select' => $this->selectMock
            ],
            '',
            true,
            true,
            true,
            ['getConnection']
        );
        $connectionMock = $this->getMockForAbstractClass(
            \Magento\Framework\DB\Adapter\AdapterInterface::class,
            [],
            '',
            true,
            true,
            true,
            ['quoteIdentifier', 'prepareSqlCondition']
        );

        $mapper->expects($this->any())
            ->method('getConnection')
            ->willReturn($connectionMock);
        $connectionMock->expects($this->any())
            ->method('quoteIdentifier')
            ->with('my-field')
            ->willReturn('quote-field');
        $connectionMock->expects($this->any())
            ->method('prepareSqlCondition')
            ->with('quote-field', $condition)
            ->willReturn($resultCondition);

        if (is_array($field)) {
            $resultCondition = '(' . implode(
                ') ' . \Magento\Framework\DB\Select::SQL_OR . ' (',
                array_fill(0, count($field), $resultCondition)
            ) . ')';
        }

        $this->selectMock->expects($this->once())
            ->method('where')
            ->with($resultCondition, null, Select::TYPE_CONDITION);

        $mapper->addFieldToFilter($field, $condition);
    }

    /**
     * Data provider for map method
     *
     * @return array
     */
    public function dataProviderMap()
    {
        return [
            [
                'mapperMethods' => [
                    'my-test-value1' => 'mapMyMapperMethodOne',
                    'my-test-value2' => 'mapMyMapperMethodTwo',
                ],
                'criteriaParts' => [
                    'my_mapper_method_one' => ['my-test-value1'],
                    'my_mapper_method_two' => ['my-test-value2'],
                ],
            ]
        ];
    }

    /**
     * Data provider for addFieldToFilter method
     *
     * @return array
     */
    public function dataProviderAddFieldToFilter()
    {
        return [
            [
                'field' => 'my-field',
                'condition' => ['condition'],
            ],
            [
                'field' => ['my-field', 'my-field'],
                'condition' => null
            ],
        ];
    }
}
