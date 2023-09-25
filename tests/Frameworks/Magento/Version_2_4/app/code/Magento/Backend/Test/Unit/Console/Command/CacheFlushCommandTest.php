<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\Console\Command;

use Magento\Backend\Console\Command\CacheFlushCommand;
use Symfony\Component\Console\Tester\CommandTester;

class CacheFlushCommandTest extends AbstractCacheManageCommandTest
{
    protected function setUp(): void
    {
        $this->cacheEventName = 'adminhtml_cache_flush_all';
        parent::setUp();
        $this->command = new CacheFlushCommand($this->cacheManagerMock, $this->eventManagerMock);
    }

    /**
     * @param array $param
     * @param array $types
     * @param string $output
     * @dataProvider executeDataProvider
     */
    public function testExecute($param, $types, $output)
    {
        $this->cacheManagerMock->expects($this->once())->method('getAvailableTypes')->willReturn(['A', 'B', 'C']);
        $this->cacheManagerMock->expects($this->once())->method('flush')->with($types);
        $this->eventManagerMock->expects($this->once())->method('dispatch')->with($this->cacheEventName);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute($param);

        $this->assertEquals($output, $commandTester->getDisplay());
    }

    /**
     * {@inheritdoc}
     */
    public function getExpectedExecutionOutput(array $types)
    {
        return 'Flushed cache types:' . PHP_EOL . implode(PHP_EOL, $types) . PHP_EOL;
    }
}
