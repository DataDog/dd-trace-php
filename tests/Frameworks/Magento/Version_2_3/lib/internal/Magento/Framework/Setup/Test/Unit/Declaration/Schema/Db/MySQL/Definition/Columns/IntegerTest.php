<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Setup\Test\Unit\Declaration\Schema\Db\MySQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Boolean;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Comment;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Identity;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Integer;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Unsigned;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\Integer as IntegerColumnDto;

class IntegerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Integer
     */
    private $integer;

    /**
     * @var Nullable|\PHPUnit\Framework\MockObject\MockObject
     */
    private $nullableMock;

    /**
     * @var Comment|\PHPUnit\Framework\MockObject\MockObject
     */
    private $commentMock;

    /**
     * @var ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceConnectionMock;

    /**
     * @var Identity|\PHPUnit\Framework\MockObject\MockObject
     */
    private $identityMock;

    /**
     * @var Unsigned|\PHPUnit\Framework\MockObject\MockObject
     */
    private $unsignedMock;

    /**
     * @var Boolean|\PHPUnit\Framework\MockObject\MockObject
     */
    private $booleanMock;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->nullableMock = $this->getMockBuilder(Nullable::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->commentMock = $this->getMockBuilder(Comment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resourceConnectionMock = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->identityMock = $this->getMockBuilder(Identity::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->unsignedMock = $this->getMockBuilder(Unsigned::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->booleanMock = $this->getMockBuilder(Boolean::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->integer = $this->objectManager->getObject(
            Integer::class,
            [
                'unsigned' => $this->unsignedMock,
                'boolean' => $this->booleanMock,
                'nullable' => $this->nullableMock,
                'identity' => $this->identityMock,
                'comment' => $this->commentMock,
                'resourceConnection' => $this->resourceConnectionMock
            ]
        );
    }

    /**
     * Test conversion to definition.
     */
    public function testToDefinition()
    {
        /** @var IntegerColumnDto|\PHPUnit\Framework\MockObject\MockObject $column */
        $column = $this->getMockBuilder(IntegerColumnDto::class)
            ->disableOriginalConstructor()
            ->getMock();
        $column->expects($this->any())
            ->method('getName')
            ->willReturn('int_column');
        $column->expects($this->any())
            ->method('getType')
            ->willReturn('int');
        $column->expects($this->any())
            ->method('getPadding')
            ->willReturn(10);
        $column->expects($this->any())
            ->method('getDefault')
            ->willReturn(0);
        $this->unsignedMock->expects($this->any())
            ->method('toDefinition')
            ->with($column)
            ->willReturn('UNSIGNED');
        $this->nullableMock->expects($this->any())
            ->method('toDefinition')
            ->with($column)
            ->willReturn('NOT NULL');
        $this->identityMock->expects($this->any())
            ->method('toDefinition')
            ->willReturn('AUTO_INCREMENT');
        $adapterMock = $this->getMockBuilder(\Magento\Framework\DB\Adapter\AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resourceConnectionMock->expects($this->once())->method('getConnection')->willReturn($adapterMock);
        $adapterMock->expects($this->once())
            ->method('quoteIdentifier')
            ->with('int_column')
            ->willReturn('`int_column`');
        $this->nullableMock->expects($this->any())
            ->method('toDefinition')
            ->with($column)
            ->willReturn('NULL');
        $this->commentMock->expects($this->any())
            ->method('toDefinition')
            ->with($column)
            ->willReturn('COMMENT "Comment"');
        $this->assertEquals(
            '`int_column` int(10) UNSIGNED NOT NULL DEFAULT 0 AUTO_INCREMENT COMMENT "Comment"',
            $this->integer->toDefinition($column)
        );
    }

    /**
     * Test from definition conversion.
     *
     * @param array $definition
     * @param bool $expectedLength
     * @dataProvider definitionDataProvider()
     */
    public function testFromDefinition($definition, $expectedLength = false)
    {
        $expectedData = [
            'definition' => $definition,
        ];
        if ($expectedLength) {
            $expectedData['padding'] = $expectedLength;
        }
        $this->unsignedMock->expects($this->any())->method('fromDefinition')->willReturnArgument(0);
        $this->identityMock->expects($this->any())->method('fromDefinition')->willReturnArgument(0);
        $this->nullableMock->expects($this->any())->method('fromDefinition')->willReturnArgument(0);
        $this->booleanMock->expects($this->any())->method('fromDefinition')->willReturnArgument(0);
        $result = $this->integer->fromDefinition(['definition' => $definition]);
        $this->assertEquals($expectedData, $result);
    }

    /**
     * @return array
     */
    public function definitionDataProvider()
    {
        return [
            ['int'],
            ['int(10)', 10],
            ['tinyint'],
            ['mediumint(5)', 5],
            ['mediumint'],
            ['smallint(3)', 3],
            ['smallint'],
            ['bigint(10)', 10],
            ['bigint'],
        ];
    }
}
