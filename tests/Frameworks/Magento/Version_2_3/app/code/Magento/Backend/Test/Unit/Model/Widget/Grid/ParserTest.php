<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Test\Unit\Model\Widget\Grid;

class ParserTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Model\Widget\Grid\Parser
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Backend\Model\Widget\Grid\Parser();
    }

    /**
     * @param string $expression
     * @param array $expected
     * @dataProvider parseExpressionDataProvider
     */
    public function testParseExpression($expression, $expected)
    {
        $this->assertEquals($expected, $this->_model->parseExpression($expression));
    }

    /**
     * @return array
     */
    public function parseExpressionDataProvider()
    {
        return [
            ['1-2', ['1', '2', '-']],
            ['1*2', ['1', '2', '*']],
            ['1/2', ['1', '2', '/']],
            ['1+2+3', ['1', '2', '+', '3', '+']],
            ['1*2*3+4', ['1', '2', '*', '3', '*', '4', '+']],
            ['1-2-3', ['1', '2', '-', '3', '-']],
            ['1*2*3', ['1', '2', '*', '3', '*']],
            ['1/2/3', ['1', '2', '/', '3', '/']],
            [
                '1 * 2 / 3 + 4 * 5 * 6 - 7 - 8',
                ['1', '2', '*', '3', '/', '4', '5', '*', '6', '*', '+', '7', '-', '8', '-']
            ]
        ];
    }

    /**
     * @param $operation
     * @param $expected
     * @dataProvider isOperationDataProvider
     */
    public function testIsOperation($operation, $expected)
    {
        $this->assertEquals($expected, $this->_model->isOperation($operation));
    }

    /**
     * @return array
     */
    public function isOperationDataProvider()
    {
        return [
            ['+', true],
            ['-', true],
            ['*', true],
            ['/', true],
            ['0', false],
            ['aa', false]
        ];
    }
}
