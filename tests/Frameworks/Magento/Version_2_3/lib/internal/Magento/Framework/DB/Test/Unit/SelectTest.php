<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\DB\Test\Unit;

use \Magento\Framework\DB\Select;

/**
 * Class SelectTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SelectTest extends \PHPUnit\Framework\TestCase
{
    public function testWhere()
    {
        $quote = new \Magento\Framework\DB\Platform\Quote();
        $renderer = new \Magento\Framework\DB\Select\SelectRenderer(
            [
                'distinct' => [
                    'renderer' => new \Magento\Framework\DB\Select\DistinctRenderer(),
                    'sort' => 100,
                    'part' => 'distinct'
                ],
                'columns' => [
                    'renderer' => new \Magento\Framework\DB\Select\ColumnsRenderer($quote),
                    'sort' => 200,
                    'part' => 'columns'
                ],
                'union' => [
                    'renderer' => new \Magento\Framework\DB\Select\UnionRenderer(),
                    'sort' => 300,
                    'part' => 'union'
                ],
                'from' => [
                    'renderer' => new \Magento\Framework\DB\Select\FromRenderer($quote),
                    'sort' => 400,
                    'part' => 'from'
                ],
                'where' => [
                    'renderer' => new \Magento\Framework\DB\Select\WhereRenderer(),
                    'sort' => 500,
                    'part' => 'where'
                ],
                'group' => [
                    'renderer' => new \Magento\Framework\DB\Select\GroupRenderer($quote),
                    'sort' => 600,
                    'part' => 'group'
                ],
                'having' => [
                    'renderer' => new \Magento\Framework\DB\Select\HavingRenderer(),
                    'sort' => 700,
                    'part' => 'having'
                ],
                'order' => [
                    'renderer' => new \Magento\Framework\DB\Select\OrderRenderer($quote),
                    'sort' => 800,
                    'part' => 'order'
                ],
                'limit' => [
                    'renderer' => new \Magento\Framework\DB\Select\LimitRenderer(),
                    'sort' => 900,
                    'part' => 'limitcount'
                ],
                'for_update' => [
                    'renderer' => new \Magento\Framework\DB\Select\ForUpdateRenderer(),
                    'sort' => 1000,
                    'part' => 'forupdate'
                ],
            ]
        );

        $select = new Select($this->_getConnectionMockWithMockedQuote(1, "'5'"), $renderer);
        $select->from('test')->where('field = ?', 5);
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (field = '5')", $select->assemble());

        $select = new Select($this->_getConnectionMockWithMockedQuote(1, "''"), $renderer);
        $select->from('test')->where('field = ?');
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (field = '')", $select->assemble());

        $select = new Select($this->_getConnectionMockWithMockedQuote(1, "'%?%'"), $renderer);
        $select->from('test')->where('field LIKE ?', '%value?%');
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (field LIKE '%?%')", $select->assemble());

        $select = new Select($this->_getConnectionMockWithMockedQuote(0), $renderer);
        $select->from('test')->where("field LIKE '%value?%'", null, Select::TYPE_CONDITION);
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (field LIKE '%value?%')", $select->assemble());

        $select = new Select($this->_getConnectionMockWithMockedQuote(1, "'1', '2', '4', '8'"), $renderer);
        $select->from('test')->where("id IN (?)", [1, 2, 4, 8]);
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (id IN ('1', '2', '4', '8'))", $select->assemble());
    }

    /**
     * Retrieve mock of adapter with mocked quote method
     *
     * @param int $callCount
     * @param string|null $returnValue
     * @return \Magento\Framework\DB\Adapter\Pdo\Mysql|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function _getConnectionMockWithMockedQuote($callCount, $returnValue = null)
    {
        $connection = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['supportStraightJoin', 'quote']
        );
        $method = $connection->expects($this->exactly($callCount))->method('quote');
        if ($callCount > 0) {
            $method->willReturn($returnValue);
        }
        return $connection;
    }
}
