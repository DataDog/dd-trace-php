<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Test\Unit\Console\Command;

use Symfony\Component\Console\Tester\CommandTester;

abstract class AbstractCacheManageCommandTest extends AbstractCacheCommandTest
{
    /** @var  string */
    protected $cacheEventName;

    /** @var  \Magento\Framework\Event\ManagerInterface | \PHPUnit\Framework\MockObject\MockObject */
    protected $eventManagerMock;

    protected function setUp(): void
    {
        $this->eventManagerMock = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        parent::setUp();
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            'implicit all' => [
                [],
                ['A', 'B', 'C'],
                $this->getExpectedExecutionOutput(['A', 'B', 'C']),
            ],
            'specified types' => [
                ['types' => ['A', 'B']],
                ['A', 'B'],
                $this->getExpectedExecutionOutput(['A', 'B']),
            ],
        ];
    }

    /**
     */
    public function testExecuteInvalidCacheType()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The following requested cache types are not supported:');

        $this->cacheManagerMock->expects($this->once())->method('getAvailableTypes')->willReturn(['A', 'B', 'C']);
        $param = ['types' => ['A', 'D']];
        $commandTester = new CommandTester($this->command);
        $commandTester->execute($param);
    }
}
