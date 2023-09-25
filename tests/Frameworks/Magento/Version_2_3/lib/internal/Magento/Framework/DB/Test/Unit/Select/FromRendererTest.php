<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DB\Test\Unit\Select;

use Magento\Framework\DB\Select;

/**
 * Class FromRendererTest
 */
class FromRendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Select\FromRenderer
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
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->quoteMock =
            $this->createPartialMock(\Magento\Framework\DB\Platform\Quote::class, ['quoteTableAs', 'quoteIdentifier']);
        $this->selectMock = $this->createPartialMock(\Magento\Framework\DB\Select::class, ['getPart']);
        $this->model = $objectManager->getObject(
            \Magento\Framework\DB\Select\FromRenderer::class,
            ['quote' => $this->quoteMock]
        );
    }

    public function testRenderNoPart()
    {
        $sql = 'SELECT';
        $this->selectMock->expects($this->once())
            ->method('getPart')
            ->with(Select::FROM)
            ->willReturn([]);
        $this->assertEquals($sql, $this->model->render($this->selectMock, $sql));
    }

    /**
     * @param array $from
     * @param string $sql
     * @param string $expectedResult
     * @dataProvider renderDataProvider
     */
    public function testRender($from, $sql, $expectedResult)
    {
        $this->quoteMock->expects($this->any())
            ->method('quoteIdentifier')
            ->willReturnArgument(0);
        $this->quoteMock->expects($this->any())
            ->method('quoteTableAs')
            ->willReturnCallback(
                function ($tableName, $correlationName) {
                    return $tableName.' AS '.$correlationName;
                }
            );
        $this->selectMock->expects($this->once())
            ->method('getPart')
            ->with(Select::FROM)
            ->willReturn($from);
        $this->assertEquals($expectedResult, $this->model->render($this->selectMock, $sql));
    }

    /**
     * Data provider for testRender
     * @return array
     */
    public function renderDataProvider()
    {
        return [
            [
                [['joinType' => Select::FROM, 'schema' => null, 'tableName' => 't1', 'joinCondition' => null]],
                'SELECT *',
                'SELECT * FROM t1 AS 0'
            ],
            [
                [
                    'a' => ['joinType' => Select::FROM, 'schema' => null, 'tableName' => 't1', 'joinCondition' => null],
                    'b' => ['joinType' => Select::FROM, 'schema' => null, 'tableName' => 't2', 'joinCondition' => null]
                ],
                'SELECT a.*',
                'SELECT a.* FROM t1 AS a' . "\n" . ' INNER JOIN t2 AS b'
            ],
            [
                [
                    'a' => ['joinType' => Select::FROM, 'schema' => null, 'tableName' => 't1', 'joinCondition' => null],
                    'b' => [
                        'joinType' => Select::LEFT_JOIN,
                        'schema' => 'db',
                        'tableName' => 't2',
                        'joinCondition' => 't1.f1 = t2.f2'
                    ]
                ],
                'SELECT b.f2',
                'SELECT b.f2 FROM t1 AS a' . "\n" . ' LEFT JOIN db.t2 AS b ON t1.f1 = t2.f2'
            ]
        ];
    }
}
