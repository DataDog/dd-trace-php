<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Indexer\Product\Flat;

use Magento\Framework\App\ResourceConnection;

class TableDataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_connectionMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var Resource|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_resourceMock;

    protected function setUp(): void
    {
        $this->_objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_connectionMock = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $this->_resourceMock = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
    }

    /**
     * @param string $flatTable
     * @param bool $isFlatTableExists
     * @param string $flatDropName
     * @param string $temporaryFlatTableName
     * @param array $expectedRenameTablesArgument
     * @dataProvider moveDataProvider
     */
    public function testMove(
        $flatTable,
        $isFlatTableExists,
        $flatDropName,
        $temporaryFlatTableName,
        $expectedRenameTablesArgument
    ) {
        $this->_connectionMock->expects($this->exactly(2))->method('dropTable')->with($flatDropName);
        $this->_connectionMock->expects(
            $this->once()
        )->method(
            'isTableExists'
        )->with(
            $flatTable
        )->willReturn(
            $isFlatTableExists
        );

        $this->_connectionMock->expects(
            $this->once()
        )->method(
            'renameTablesBatch'
        )->with(
            $expectedRenameTablesArgument
        );

        $this->_resourceMock->expects(
            $this->any()
        )->method(
            'getConnection'
        )->willReturn(
            $this->_connectionMock
        );

        $model = $this->_objectManager->getObject(
            \Magento\Catalog\Model\Indexer\Product\Flat\TableData::class,
            ['resource' => $this->_resourceMock]
        );

        $model->move($flatTable, $flatDropName, $temporaryFlatTableName);
    }

    /**
     * @return array
     */
    public function moveDataProvider()
    {
        return [
            [
                'flat_table',
                true,
                'flat_table_to_drop',
                'flat_tmp',
                [
                    ['oldName' => 'flat_table', 'newName' => 'flat_table_to_drop'],
                    ['oldName' => 'flat_tmp', 'newName' => 'flat_table']
                ],
            ],
            [
                'flat_table',
                false,
                'flat_table_to_drop',
                'flat_tmp',
                [['oldName' => 'flat_tmp', 'newName' => 'flat_table']]
            ]
        ];
    }
}
