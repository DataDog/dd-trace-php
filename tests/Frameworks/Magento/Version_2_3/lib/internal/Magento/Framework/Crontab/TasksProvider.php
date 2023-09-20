<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Crontab;

/**
 * TasksProvider collects list of tasks
 */
class TasksProvider implements TasksProviderInterface
{
    /**
     * @var array
     */
    private $tasks = [];

    /**
     * @param array $tasks
     */
    public function __construct(array $tasks = [])
    {
        $this->tasks = $tasks;
    }

    /**
     * {@inheritdoc}
     */
    public function getTasks()
    {
        return $this->tasks;
    }
}
