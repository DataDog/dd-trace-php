<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Crontab\Test\Unit;

use Magento\Framework\Crontab\TasksProvider;

class TasksProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @return void
     */
    public function testTasksProviderEmpty()
    {
        /** @var $tasksProvider $tasksProvider */
        $tasksProvider = new TasksProvider();
        $this->assertSame([], $tasksProvider->getTasks());
    }

    public function testTasksProvider()
    {
        $tasks = [
            'magentoCron' => ['expressin' => '* * * * *', 'command' => 'bin/magento cron:run'],
        ];

        /** @var $tasksProvider $tasksProvider */
        $tasksProvider = new TasksProvider($tasks);
        $this->assertSame($tasks, $tasksProvider->getTasks());
    }
}
