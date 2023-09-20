<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DB\Test\Unit\Select;

use Magento\Framework\DB\Select;

/**
 * Class WhereRendererTest
 */
class WhereRendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Select\WhereRenderer
     */
    protected $model;

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
        $this->selectMock = $this->createPartialMock(\Magento\Framework\DB\Select::class, ['getPart']);
        $this->model = $objectManager->getObject(\Magento\Framework\DB\Select\WhereRenderer::class);
    }

    /**
     * @param array $mapValues
     * @dataProvider renderNoPartDataProvider
     */
    public function testRenderNoPart($mapValues)
    {
        $sql = 'SELECT';
        $this->selectMock->expects($this->any())
            ->method('getPart')
            ->willReturnMap($mapValues);
        $this->assertEquals($sql, $this->model->render($this->selectMock, $sql));
    }

    /**
     * Data provider for testRenderNoPart
     * @return array
     */
    public function renderNoPartDataProvider()
    {
        return [
            [[[Select::FROM, false], [Select::WHERE, false]]],
            [[[Select::FROM, true], [Select::WHERE, false]]],
            [[[Select::FROM, false], [Select::WHERE, true]]],
        ];
    }

    public function testRender()
    {
        $sql = 'SELECT';
        $expectedResult = $sql . ' ' . Select::SQL_WHERE . ' where1 where2';
        $mapValues = [
            [Select::FROM, true],
            [Select::WHERE, ['where1', 'where2']]
        ];
        $this->selectMock->expects($this->any())
            ->method('getPart')
            ->willReturnMap($mapValues);
        $this->assertEquals($expectedResult, $this->model->render($this->selectMock, $sql));
    }
}
