<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Store\Console\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Class WebsiteListCommand
 *
 * Command for listing the configured websites
 */
class WebsiteListCommand extends Command
{
    /**
     * @var \Magento\Store\Api\WebsiteManagementInterface
     */
    private $manager;

    /**
     */
    public function __construct(
        \Magento\Store\Api\WebsiteRepositoryInterface $websiteManagement
    ) {
        $this->manager = $websiteManagement;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('store:website:list')
            ->setDescription('Displays the list of websites');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $table = new Table($output);
            $table->setHeaders(['ID', 'Default Group Id', 'Name', 'Code', 'Sort Order', 'Is Default']);

            foreach ($this->manager->getList() as $website) {
                $table->addRow([
                    $website->getId(),
                    $website->getDefaultGroupId(),
                    $website->getName(),
                    $website->getCode(),
                    $website->getData('sort_order'),
                    $website->getData('is_default'),
                ]);
            }

            $table->render();

            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln($e->getTraceAsString());
            }

            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}
