<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\DB\Test\Unit\Select;

use Magento\Framework\DB\Select;

/**
 * Class DistinctRendererTest
 */
class DistinctRendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Select\DistinctRenderer
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
        $this->model = $objectManager->getObject(\Magento\Framework\DB\Select\DistinctRenderer::class);
    }

    public function testRenderNoPart()
    {
        $sql = 'SELECT';
        $this->selectMock->expects($this->once())
            ->method('getPart')
            ->with(Select::DISTINCT)
            ->willReturn(false);
        $this->assertEquals($sql, $this->model->render($this->selectMock, $sql));
    }

    public function testRender()
    {
        $sql = 'SELECT';
        $expectedResult = $sql . ' ' . Select::SQL_DISTINCT  . ' ';
        $this->selectMock->expects($this->once())
            ->method('getPart')
            ->with(Select::DISTINCT)
            ->willReturn(true);
        $this->assertEquals($expectedResult, $this->model->render($this->selectMock, $sql));
    }
}
