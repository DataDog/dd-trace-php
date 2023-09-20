<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Indexer;
use Magento\Framework\Mview;
use Symfony\Component\Console\Helper\Table as TableHelper;

/**
 * Command for displaying status of indexers.
 */
class IndexerStatusCommand extends AbstractIndexerManageCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('indexer:status')
            ->setDescription('Shows status of Indexer')
            ->setDefinition($this->getInputList());

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var TableHelper $table */
        $table = $this->getObjectManager()->create(TableHelper::class, ['output' => $output]);
        $table->setHeaders(['Title', 'Status', 'Update On', 'Schedule Status', 'Schedule Updated']);

        $rows = [];

        $indexers = $this->getIndexers($input);
        foreach ($indexers as $indexer) {
            $view = $indexer->getView();

            $rowData = [
                'Title'             => $indexer->getTitle(),
                'Status'            => $this->getStatus($indexer),
                'Update On'         => $indexer->isScheduled() ? 'Schedule' : 'Save',
                'Schedule Status'   => '',
                'Updated'           => '',
            ];

            if ($indexer->isScheduled()) {
                $state = $view->getState();
                $rowData['Schedule Status'] = "{$state->getStatus()} ({$this->getPendingCount($view)} in backlog)";
                $rowData['Updated'] = $state->getUpdated();
            }

            $rows[] = $rowData;
        }

        usort($rows, function ($comp1, $comp2) {
            return strcmp($comp1['Title'], $comp2['Title']);
        });

        $table->addRows($rows);
        $table->render();
    }

    /**
     * @param Indexer\IndexerInterface $indexer
     * @return string
     */
    private function getStatus(Indexer\IndexerInterface $indexer)
    {
        $status = 'unknown';
        switch ($indexer->getStatus()) {
            case \Magento\Framework\Indexer\StateInterface::STATUS_VALID:
                $status = 'Ready';
                break;
            case \Magento\Framework\Indexer\StateInterface::STATUS_INVALID:
                $status = 'Reindex required';
                break;
            case \Magento\Framework\Indexer\StateInterface::STATUS_WORKING:
                $status = 'Processing';
                break;
        }
        return $status;
    }

    /**
     * @param Mview\ViewInterface $view
     * @return string
     */
    private function getPendingCount(Mview\ViewInterface $view)
    {
        $changelog = $view->getChangelog();

        try {
            $currentVersionId = $changelog->getVersion();
        } catch (Mview\View\ChangelogTableNotExistsException $e) {
            return '';
        }

        $state = $view->getState();

        $pendingCount = count($changelog->getList($state->getVersionId(), $currentVersionId));

        $pendingString = "<error>$pendingCount</error>";
        if ($pendingCount <= 0) {
            $pendingString = "<info>$pendingCount</info>";
        }

        return $pendingString;
    }
}
