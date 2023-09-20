<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DB\Test\Unit\Select;

use Magento\Framework\DB\Select;

/**
 * Class ColumnsRendererTest
 */
class ColumnsRendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Select\ColumnsRenderer
     */
    protected $model;

    /**
     * @var \Magento\Framework\DB\Platform\Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $quoteMock;

    /**
     * @var \Magento\Framework\DB\Select|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    /**
     * @var \Zend_Db_Expr
     */
    protected $sqlWildcard;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->quoteMock = $this->createPartialMock(\Magento\Framework\DB\Platform\Quote::class, ['quoteColumnAs']);
        $this->selectMock = $this->createPartialMock(\Magento\Framework\DB\Select::class, ['getPart']);
        $this->model = $objectManager->getObject(
            \Magento\Framework\DB\Select\ColumnsRenderer::class,
            ['quote' => $this->quoteMock]
        );
        $this->sqlWildcard = new \Zend_Db_Expr(Select::SQL_WILDCARD);
    }

    public function testRenderNotColumns()
    {
        $this->selectMock->expects($this->once())
            ->method('getPart')
            ->with(Select::COLUMNS)
            ->willReturn([]);
        $this->assertNull($this->model->render($this->selectMock));
    }

    /**
     * @param array $columns
     * @param string $sql
     * @param string $expectedResult
     * @dataProvider renderDataProvider
     */
    public function testRender($columns, $sql, $expectedResult)
    {
        $mapValues = [
            ['column', null, '`column`'],
            [['table', 'column'], null, '`table`.`column`'],
            [['table', 'column'], 'alias', '`table`.`column` AS `alias`'],
        ];
        $this->quoteMock->expects($this->any())
            ->method('quoteColumnAs')
            ->willReturnMap($mapValues);
        $this->selectMock->expects($this->exactly(2))
            ->method('getPart')
            ->with(Select::COLUMNS)
            ->willReturn($columns);
        $this->assertEquals($expectedResult, $this->model->render($this->selectMock, $sql));
    }

    /**
     * @return array
     */
    public function renderDataProvider()
    {
        return [
            [[['', 'column', null]], 'SELECT', 'SELECT `column`'],
            [[['table', 'column', null]], 'SELECT', 'SELECT `table`.`column`'],
            [[['table', 'column', 'alias']], 'SELECT', 'SELECT `table`.`column` AS `alias`'],
        ];
    }
}
