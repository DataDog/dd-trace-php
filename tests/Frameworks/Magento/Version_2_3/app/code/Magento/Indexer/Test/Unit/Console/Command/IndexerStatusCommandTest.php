<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Test\Unit\Console\Command;

use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Console\Command\IndexerStatusCommand;
use Symfony\Component\Console\Tester\CommandTester;

class IndexerStatusCommandTest extends AbstractIndexerCommandCommonSetup
{
    /**
     * Command being tested
     *
     * @var IndexerStatusCommand
     */
    private $command;

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject $indexerMock
     * @param array $data
     * @return mixed
     */
    private function attachViewToIndexerMock($indexerMock, array $data)
    {
        /** @var \Magento\Framework\Mview\View\Changelog|\PHPUnit\Framework\MockObject\MockObject $changelog */
        $changelog = $this->getMockBuilder(\Magento\Framework\Mview\View\Changelog::class)
            ->disableOriginalConstructor()
            ->getMock();

        $changelog->expects($this->any())
            ->method('getList')
            ->willReturn(range(0, $data['view']['changelog']['list_size']-1));

        /** @var \Magento\Indexer\Model\Mview\View\State|\PHPUnit\Framework\MockObject\MockObject $stateMock */
        $stateMock = $this->getMockBuilder(\Magento\Indexer\Model\Mview\View\State::class)
            ->disableOriginalConstructor()
            ->setMethods(null)
            ->getMock();

        $stateMock->addData($data['view']['state']);

        /** @var \Magento\Framework\Mview\View|\PHPUnit\Framework\MockObject\MockObject $viewMock */
        $viewMock = $this->getMockBuilder(\Magento\Framework\Mview\View::class)
            ->disableOriginalConstructor()
            ->setMethods(['getChangelog', 'getState'])
            ->getMock();

        $viewMock->expects($this->any())
            ->method('getState')
            ->willReturn($stateMock);
        $viewMock->expects($this->any())
            ->method('getChangelog')
            ->willReturn($changelog);

        $indexerMock->method('getView')
            ->willReturn($viewMock);

        return $indexerMock;
    }

    /**
     * @param array $indexers
     *
     * @dataProvider executeAllDataProvider
     */
    public function testExecuteAll(array $indexers)
    {
        $this->configureAdminArea();
        $indexerMocks = [];
        foreach ($indexers as $indexerData) {
            $indexerMock = $this->getIndexerMock(
                ['getStatus', 'isScheduled', 'getState', 'getView'],
                $indexerData
            );

            $indexerMock->method('getStatus')
                ->willReturn($indexerData['status']);
            $indexerMock->method('isScheduled')
                ->willReturn($indexerData['is_scheduled']);

            if ($indexerData['is_scheduled']) {
                $this->attachViewToIndexerMock($indexerMock, $indexerData);
            }

            $indexerMocks[] = $indexerMock;
        }

        $this->initIndexerCollectionByItems($indexerMocks);
        $this->command = new IndexerStatusCommand($this->objectManagerFactory);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);

        $linesOutput = array_filter(explode(PHP_EOL, $commandTester->getDisplay()));

        $spacer = '+-----------+----------------+------------------+-----------+-------------------------+'
            . '---------------------+';

        $this->assertCount(8, $linesOutput, 'There should be 8 lines output. 3 Spacers, 1 header, 4 content.');
        $this->assertEquals($linesOutput[0], $spacer, "Lines 0, 2, 7 should be spacer lines");
        $this->assertEquals($linesOutput[2], $spacer, "Lines 0, 2, 7 should be spacer lines");
        $this->assertEquals($linesOutput[7], $spacer, "Lines 0, 2, 7 should be spacer lines");

        $headerValues = array_values(array_filter(explode('|', $linesOutput[1])));
        $this->assertEquals('ID', trim($headerValues[0]));
        $this->assertEquals('Title', trim($headerValues[1]));
        $this->assertEquals('Status', trim($headerValues[2]));
        $this->assertEquals('Update On', trim($headerValues[3]));
        $this->assertEquals('Schedule Status', trim($headerValues[4]));
        $this->assertEquals('Schedule Updated', trim($headerValues[5]));

        $indexer1 = array_values(array_filter(explode('|', $linesOutput[3])));
        $this->assertEquals('indexer_1', trim($indexer1[0]));
        $this->assertEquals('Title_indexer1', trim($indexer1[1]));
        $this->assertEquals('Ready', trim($indexer1[2]));
        $this->assertEquals('Schedule', trim($indexer1[3]));
        $this->assertEquals('idle (10 in backlog)', trim($indexer1[4]));
        $this->assertEquals('2017-01-01 11:11:11', trim($indexer1[5]));

        $indexer2 = array_values(array_filter(explode('|', $linesOutput[4])));
        $this->assertEquals('indexer_2', trim($indexer2[0]));
        $this->assertEquals('Title_indexer2', trim($indexer2[1]));
        $this->assertEquals('Reindex required', trim($indexer2[2]));
        $this->assertEquals('Save', trim($indexer2[3]));
        $this->assertEquals('', trim($indexer2[4]));
        $this->assertEquals('', trim($indexer2[5]));

        $indexer3 = array_values(array_filter(explode('|', $linesOutput[5])));
        $this->assertEquals('indexer_3', trim($indexer3[0]));
        $this->assertEquals('Title_indexer3', trim($indexer3[1]));
        $this->assertEquals('Processing', trim($indexer3[2]));
        $this->assertEquals('Schedule', trim($indexer3[3]));
        $this->assertEquals('idle (100 in backlog)', trim($indexer3[4]));
        $this->assertEquals('2017-01-01 11:11:11', trim($indexer3[5]));

        $indexer4 = array_values(array_filter(explode('|', $linesOutput[6])));
        $this->assertEquals('indexer_4', trim($indexer4[0]));
        $this->assertEquals('Title_indexer4', trim($indexer4[1]));
        $this->assertEquals('unknown', trim($indexer4[2]));
        $this->assertEquals('Schedule', trim($indexer4[3]));
        $this->assertEquals('running (20 in backlog)', trim($indexer4[4]));
        $this->assertEquals('2017-01-01 11:11:11', trim($indexer4[5]));
    }

    /**
     * @return array
     */
    public function executeAllDataProvider()
    {
        return [
            [
                'indexers' => [
                    'indexer_1' => [
                        'indexer_id' => 'indexer_1',
                        'title' => 'Title_indexer1',
                        'status' => StateInterface::STATUS_VALID,
                        'is_scheduled' => true,
                        'view' => [
                            'state' => [
                                'status' => 'idle',
                                'updated' => '2017-01-01 11:11:11',
                            ],
                            'changelog' => [
                                'list_size' => 10
                            ]
                        ]
                    ],
                    'indexer_2' => [
                        'indexer_id' => 'indexer_2',
                        'title' => 'Title_indexer2',
                        'status' => StateInterface::STATUS_INVALID,
                        'is_scheduled' => false,
                        'view' => [
                            'state' => [
                                'status' => 'idle',
                                'updated' => '2017-01-01 11:11:11',
                            ],
                            'changelog' => [
                                'list_size' => 99999999
                            ]
                        ]
                    ],
                    'indexer_3' => [
                        'indexer_id' => 'indexer_3',
                        'title' => 'Title_indexer3',
                        'status' => StateInterface::STATUS_WORKING,
                        'is_scheduled' => true,
                        'view' => [
                            'state' => [
                                'status' => 'idle',
                                'updated' => '2017-01-01 11:11:11',
                            ],
                            'changelog' => [
                                'list_size' => 100
                            ]
                        ]
                    ],
                    'indexer_4' => [
                        'indexer_id' => 'indexer_4',
                        'title' => 'Title_indexer4',
                        'status' => null,
                        'is_scheduled' => true,
                        'view' => [
                            'state' => [
                                'status' => 'running',
                                'updated' => '2017-01-01 11:11:11',
                            ],
                            'changelog' => [
                                'list_size' => 20
                            ]
                        ]
                    ],
                ],
            ],
        ];
    }
}
