<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Cache\Manager;

/**
 * phpcs:disable Magento2.Classes.AbstractApi
 * @api
 * @since 100.0.2
 */
abstract class AbstractCacheTypeManageCommand extends AbstractCacheManageCommand
{
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

    /**
     * @param Manager $cacheManager
     * @param EventManagerInterface $eventManager
     */
    public function __construct(
        Manager $cacheManager,
        EventManagerInterface $eventManager
    ) {
        $this->eventManager = $eventManager;
        parent::__construct($cacheManager);
    }

    /**
     * Perform a cache management action on cache types
     *
     * @param array $cacheTypes
     * @return void
     */
    abstract protected function performAction(array $cacheTypes);

    /**
     * Get display message
     *
     * @return string
     */
    abstract protected function getDisplayMessage();

    /**
     * Perform cache management action
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $types = $this->getRequestedTypes($input);
        $this->performAction($types);
        $output->writeln($this->getDisplayMessage());
        $output->writeln(join(PHP_EOL, $types));

        return Cli::RETURN_SUCCESS;
    }
}
