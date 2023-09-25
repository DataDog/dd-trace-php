<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Test\Unit\Model\Menu;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class BuilderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Model\Menu\Builder
     */
    private $model;

    /**
     * @var \Magento\Backend\Model\Menu|\PHPUnit\Framework\MockObject\MockObject
     */
    private $menuMock;

    /**
     * @var \Magento\Backend\Model\Menu\Item\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $factoryMock;

    protected function setUp(): void
    {
        $this->factoryMock = $this->createMock(\Magento\Backend\Model\Menu\Item\Factory::class);
        $this->menuMock = $this->createPartialMock(\Magento\Backend\Model\Menu::class, ['addChild', 'add']);

        $this->model = (new ObjectManager($this))->getObject(
            \Magento\Backend\Model\Menu\Builder::class,
            [
                'menuItemFactory' => $this->factoryMock
            ]
        );
    }

    public function testProcessCommand()
    {
        $command = $this->createMock(\Magento\Backend\Model\Menu\Builder\Command\Add::class);
        $command->expects($this->any())->method('getId')->willReturn(1);
        $command2 = $this->createMock(\Magento\Backend\Model\Menu\Builder\Command\Update::class);
        $command2->expects($this->any())->method('getId')->willReturn(1);
        $command->expects($this->once())->method('chain')->with($this->equalTo($command2));
        $this->model->processCommand($command);
        $this->model->processCommand($command2);
    }

    public function testGetResultBuildsTreeStructure()
    {
        $item1 = $this->createMock(\Magento\Backend\Model\Menu\Item::class);
        $item1->expects($this->once())->method('getChildren')->willReturn($this->menuMock);
        $this->factoryMock->expects($this->any())->method('create')->willReturn($item1);

        $item2 = $this->createMock(\Magento\Backend\Model\Menu\Item::class);
        $this->factoryMock->expects($this->at(1))->method('create')->willReturn($item2);

        $this->menuMock->expects(
            $this->at(0)
        )->method(
            'add'
        )->with(
            $this->isInstanceOf(\Magento\Backend\Model\Menu\Item::class),
            $this->equalTo(null),
            $this->equalTo(2)
        );

        $this->menuMock->expects(
            $this->at(1)
        )->method(
            'add'
        )->with(
            $this->isInstanceOf(\Magento\Backend\Model\Menu\Item::class),
            $this->equalTo(null),
            $this->equalTo(4)
        );

        $this->model->processCommand(
            new \Magento\Backend\Model\Menu\Builder\Command\Add(
                [
                    'id' => 'item1',
                    'title' => 'Item 1',
                    'module' => 'Magento_Backend',
                    'sortOrder' => 2,
                    'resource' => 'Magento_Backend::item1',
                ]
            )
        );
        $this->model->processCommand(
            new \Magento\Backend\Model\Menu\Builder\Command\Add(
                [
                    'id' => 'item2',
                    'parent' => 'item1',
                    'title' => 'two',
                    'module' => 'Magento_Backend',
                    'sortOrder' => 4,
                    'resource' => 'Magento_Backend::item2',
                ]
            )
        );

        $this->model->getResult($this->menuMock);
    }

    public function testGetResultSkipsRemovedItems()
    {
        $this->model->processCommand(
            new \Magento\Backend\Model\Menu\Builder\Command\Add(
                [
                    'id' => 1,
                    'title' => 'Item 1',
                    'module' => 'Magento_Backend',
                    'resource' => 'Magento_Backend::i1',
                ]
            )
        );
        $this->model->processCommand(new \Magento\Backend\Model\Menu\Builder\Command\Remove(['id' => 1]));

        $this->menuMock->expects($this->never())->method('addChild');

        $this->model->getResult($this->menuMock);
    }

    /**
     */
    public function testGetResultSkipItemsWithInvalidParent()
    {
        $this->expectException(\OutOfRangeException::class);

        $item1 = $this->createMock(\Magento\Backend\Model\Menu\Item::class);
        $this->factoryMock->expects($this->any())->method('create')->willReturn($item1);

        $this->model->processCommand(
            new \Magento\Backend\Model\Menu\Builder\Command\Add(
                [
                    'id' => 'item1',
                    'parent' => 'not_exists',
                    'title' => 'Item 1',
                    'module' => 'Magento_Backend',
                    'resource' => 'Magento_Backend::item1',
                ]
            )
        );

        $this->model->getResult($this->menuMock);
    }
}
