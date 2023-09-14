<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\Model\Widget\Grid;

use Magento\Backend\Model\Widget\Grid\AbstractTotals;
use Magento\Backend\Model\Widget\Grid\Parser;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractTotalsTest extends TestCase
{
    /**
     * @var MockObject $_model
     */
    protected $_model;

    /**
     * @var MockObject
     */
    protected $_parserMock;

    /**
     * @var MockObject
     */
    protected $_factoryMock;

    /**
     * Columns map for parserMock return expressions
     *
     * @var array
     */
    protected $_columnsValueMap;

    protected function setUp(): void
    {
        $this->_prepareParserMock();
        $this->_prepareFactoryMock();

        $arguments = ['factory' => $this->_factoryMock, 'parser' => $this->_parserMock];
        $this->_model = $this->getMockForAbstractClass(
            AbstractTotals::class,
            $arguments,
            '',
            true,
            false,
            true,
            []
        );
        $this->_model->expects($this->any())->method('_countSum')->willReturn(2);
        $this->_model->expects($this->any())->method('_countAverage')->willReturn(2);

        $this->_setUpColumns();
    }

    protected function tearDown(): void
    {
        unset($this->_parserMock);
        unset($this->_factoryMock);
    }

    /**
     * Retrieve test collection
     *
     * @return Collection
     */
    protected function _getTestCollection()
    {
        $collection = new Collection(
            $this->createMock(EntityFactory::class)
        );
        $items = [new DataObject(['test1' => '1', 'test2' => '2'])];
        foreach ($items as $item) {
            $collection->addItem($item);
        }

        return $collection;
    }

    /**
     * Prepare tested model by setting columns
     */
    protected function _setUpColumns()
    {
        $columns = [
            'test1' => 'sum',
            'test2' => 'avg',
            'test3' => 'test1+test2',
            'test4' => 'test1-test2',
            'test5' => 'test1*test2',
            'test6' => 'test1/test2',
            'test7' => 'test1/0',
        ];

        foreach ($columns as $index => $expression) {
            $this->_model->setColumn($index, $expression);
        }
    }

    /**
     * Prepare parser mock by setting test expressions for columns and operation used
     */
    protected function _prepareParserMock()
    {
        $this->_parserMock = $this->createPartialMock(
            Parser::class,
            ['parseExpression', 'isOperation']
        );

        $columnsValueMap = [
            ['test1+test2', ['test1', 'test2', '+']],
            ['test1-test2', ['test1', 'test2', '-']],
            ['test1*test2', ['test1', 'test2', '*']],
            ['test1/test2', ['test1', 'test2', '/']],
            ['test1/0', ['test1', '0', '/']],
        ];
        $this->_parserMock->expects(
            $this->any()
        )->method(
            'parseExpression'
        )->willReturnMap(
            $columnsValueMap
        );

        $isOperationValueMap = [
            ['+', true],
            ['-', true],
            ['*', true],
            ['/', true],
            ['test1', false],
            ['test2', false],
            ['0', false],
        ];
        $this->_parserMock->expects(
            $this->any()
        )->method(
            'isOperation'
        )->willReturnMap(
            $isOperationValueMap
        );
    }

    /**
     * Prepare factory mock for setting possible values
     */
    protected function _prepareFactoryMock()
    {
        $this->_factoryMock = $this->createPartialMock(Factory::class, ['create']);

        $createValueMap = [
            [
                [
                    'test1' => 2,
                    'test2' => 2,
                    'test3' => 4,
                    'test4' => 0,
                    'test5' => 4,
                    'test6' => 1,
                    'test7' => 0,
                ],
                new DataObject(
                    [
                        'test1' => 2,
                        'test2' => 2,
                        'test3' => 4,
                        'test4' => 0,
                        'test5' => 4,
                        'test6' => 1,
                        'test7' => 0,
                    ]
                ),
            ],
            [[], new DataObject()],
        ];
        $this->_factoryMock->expects($this->any())->method('create')->willReturnMap($createValueMap);
    }

    public function testColumns()
    {
        $expected = [
            'test1' => 'sum',
            'test2' => 'avg',
            'test3' => 'test1+test2',
            'test4' => 'test1-test2',
            'test5' => 'test1*test2',
            'test6' => 'test1/test2',
            'test7' => 'test1/0',
        ];

        $this->assertEquals($expected, $this->_model->getColumns());
    }

    public function testCountTotals()
    {
        $expected = new DataObject(
            ['test1' => 2, 'test2' => 2, 'test3' => 4, 'test4' => 0, 'test5' => 4, 'test6' => 1, 'test7' => 0]
        );
        $this->assertEquals($expected, $this->_model->countTotals($this->_getTestCollection()));
    }

    public function testReset()
    {
        $this->_model->countTotals($this->_getTestCollection());
        $this->_model->reset();

        $this->assertEquals(new DataObject(), $this->_model->getTotals());
        $this->assertNotEmpty($this->_model->getColumns());
    }

    public function testResetFull()
    {
        $this->_model->countTotals($this->_getTestCollection());
        $this->_model->reset(true);

        $this->assertEquals(new DataObject(), $this->_model->getTotals());
        $this->assertEmpty($this->_model->getColumns());
    }
}
