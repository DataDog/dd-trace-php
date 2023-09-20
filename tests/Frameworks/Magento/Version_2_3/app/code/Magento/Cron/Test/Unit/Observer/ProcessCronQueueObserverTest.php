<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cron\Test\Unit\Observer;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Observer\ProcessCronQueueObserver;
use Magento\Framework\App\State;
use Magento\Framework\Profiler\Driver\Standard\Stat;
use Magento\Framework\Profiler\Driver\Standard\StatFactory;
use Magento\Cron\Model\DeadlockRetrierInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Class \Magento\Cron\Test\Unit\Model\ObserverTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class ProcessCronQueueObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ProcessCronQueueObserver
     */
    protected $_observer;

    /**
     * @var \Magento\Framework\App\ObjectManager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_objectManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_cache;

    /**
     * @var \Magento\Cron\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_config;

    /**
     * @var \Magento\Cron\Model\ScheduleFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_scheduleFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_scopeConfig;

    /**
     * @var \Magento\Framework\App\Console\Request|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_request;

    /**
     * @var \Magento\Framework\ShellInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_shell;

    /** @var \Magento\Cron\Model\ResourceModel\Schedule\Collection|\PHPUnit\Framework\MockObject\MockObject */
    protected $_collection;

    /**
     * @var \Magento\Cron\Model\Groups\Config\Data
     */
    protected $_cronGroupConfig;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTimeMock;

    /**
     * @var \Magento\Framework\Event\Observer
     */
    protected $observer;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\App\State|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $appStateMock;

    /**
     * @var \Magento\Framework\Lock\LockManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $lockManagerMock;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventManager;

    /**
     * @var DeadlockRetrierInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $retrierMock;

    /**
     * @var \Magento\Cron\Model\ResourceModel\Schedule|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scheduleResource;

    /**
     * @var StatFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $statFactory;

    /**
     * @var Stat|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stat;

    /**
     * @var int
     */
    protected $time = 1501538400;

    /**
     * Prepare parameters
     */
    protected function setUp(): void
    {
        $this->_objectManager = $this->getMockBuilder(
            \Magento\Framework\App\ObjectManager::class
        )->disableOriginalConstructor()->getMock();
        $this->_cache = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $this->_config = $this->getMockBuilder(
            \Magento\Cron\Model\Config::class
        )->disableOriginalConstructor()->getMock();
        $this->_scopeConfig = $this->getMockBuilder(
            \Magento\Framework\App\Config\ScopeConfigInterface::class
        )->disableOriginalConstructor()->getMock();
        $this->_collection = $this->getMockBuilder(
            \Magento\Cron\Model\ResourceModel\Schedule\Collection::class
        )->setMethods(
            ['addFieldToFilter', 'load', '__wakeup']
        )->disableOriginalConstructor()->getMock();
        $this->_collection->expects($this->any())->method('addFieldToFilter')->willReturnSelf();
        $this->_collection->expects($this->any())->method('load')->willReturnSelf();

        $this->_scheduleFactory = $this->getMockBuilder(
            \Magento\Cron\Model\ScheduleFactory::class
        )->setMethods(
            ['create']
        )->disableOriginalConstructor()->getMock();
        $this->_request = $this->getMockBuilder(
            \Magento\Framework\App\Console\Request::class
        )->disableOriginalConstructor()->getMock();
        $this->_shell = $this->getMockBuilder(
            \Magento\Framework\ShellInterface::class
        )->disableOriginalConstructor()->setMethods(
            ['execute']
        )->getMock();
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->appStateMock = $this->getMockBuilder(\Magento\Framework\App\State::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->lockManagerMock = $this->getMockBuilder(\Magento\Framework\Lock\LockManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->lockManagerMock->method('lock')->willReturn(true);
        $this->lockManagerMock->method('unlock')->willReturn(true);

        $this->eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);

        $this->observer = $this->createMock(\Magento\Framework\Event\Observer::class);

        $this->dateTimeMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\DateTime::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->dateTimeMock->expects($this->any())->method('gmtTimestamp')->willReturn($this->time);

        $phpExecutableFinder = $this->createMock(\Symfony\Component\Process\PhpExecutableFinder::class);
        $phpExecutableFinder->expects($this->any())->method('find')->willReturn('php');
        $phpExecutableFinderFactory = $this->createMock(
            \Magento\Framework\Process\PhpExecutableFinderFactory::class
        );
        $phpExecutableFinderFactory->expects($this->any())->method('create')->willReturn($phpExecutableFinder);

        $this->scheduleResource = $this->getMockBuilder(\Magento\Cron\Model\ResourceModel\Schedule::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->statFactory = $this->getMockBuilder(StatFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->stat = $this->getMockBuilder(\Magento\Framework\Profiler\Driver\Standard\Stat::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->statFactory->expects($this->any())->method('create')->willReturn($this->stat);

        $this->retrierMock = $this->getMockForAbstractClass(DeadlockRetrierInterface::class);

        $this->_observer = new ProcessCronQueueObserver(
            $this->_objectManager,
            $this->_scheduleFactory,
            $this->_cache,
            $this->_config,
            $this->_scopeConfig,
            $this->_request,
            $this->_shell,
            $this->dateTimeMock,
            $phpExecutableFinderFactory,
            $this->loggerMock,
            $this->appStateMock,
            $this->statFactory,
            $this->lockManagerMock,
            $this->eventManager,
            $this->retrierMock
        );
    }

    /**
     * Test case for not existed cron jobs in files but in data base is presented
     */
    public function testDispatchNoJobConfig()
    {
        $this->eventManager->expects($this->never())->method('dispatch');
        $lastRun = $this->time + 10000000;
        $this->_cache->expects($this->atLeastOnce())->method('load')->willReturn($lastRun);
        $this->_scopeConfig->expects($this->atLeastOnce())->method('getValue')->willReturn(0);

        $this->_config->expects($this->atLeastOnce())->method('getJobs')->willReturn(['test_job1' => ['test_data']]);

        $schedule = $this->createPartialMock(\Magento\Cron\Model\Schedule::class, ['getJobCode', '__wakeup']);
        $schedule->expects($this->atLeastOnce())->method('getJobCode')->willReturn('not_existed_job_code');

        $this->_collection->addItem($schedule);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->atLeastOnce())
            ->method('getCollection')
            ->willReturn($this->_collection);
        $this->_scheduleFactory->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($scheduleMock);

        $this->_observer->execute($this->observer);
    }

    /**
     * Test case checks if some job can't be locked
     */
    public function testDispatchCanNotLock()
    {
        $lastRun = $this->time + 10000000;
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->_cache->expects($this->any())->method('load')->willReturn($lastRun);
        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn(0);
        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');

        $dateScheduledAt = date('Y-m-d H:i:s', $this->time - 86400);
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            ['getJobCode', 'tryLockJob', 'getScheduledAt', '__wakeup', 'save', 'setFinishedAt', 'getResource']
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('test_job1');
        $schedule->expects($this->atLeastOnce())->method('getScheduledAt')->willReturn($dateScheduledAt);
        $schedule->expects($this->exactly(5))->method('tryLockJob')->willReturn(false);
        $schedule->expects($this->never())->method('setFinishedAt');
        $schedule->expects($this->once())->method('getResource')->willReturn($this->scheduleResource);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->scheduleResource->expects($this->once())
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->once())
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        $abstractModel = $this->createMock(\Magento\Framework\Model\AbstractModel::class);
        $schedule->expects($this->any())->method('save')->willReturn($abstractModel);
        $this->_collection->addItem($schedule);

        $this->_config->expects(
            $this->exactly(2)
        )->method(
            'getJobs'
        )->willReturn(['test_group' => ['test_job1' => ['test_data']]]);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $scheduleMock->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->atLeastOnce())
            ->method('create')
            ->willReturn($scheduleMock);

        $this->_observer->execute($this->observer);
    }

    /**
     * Test case catch exception if too late for schedule
     */
    public function testDispatchExceptionTooLate()
    {
        $exceptionMessage = 'Cron Job test_job1 is missed at 2017-07-30 15:00:00';
        $jobCode = 'test_job1';

        $lastRun = $this->time + 10000000;
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->_cache->expects($this->any())->method('load')->willReturn($lastRun);
        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn(0);
        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');

        $dateScheduledAt = date('Y-m-d H:i:s', $this->time - 86400);
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            [
                'getJobCode',
                'tryLockJob',
                'getScheduledAt',
                'save',
                'setStatus',
                'setMessages',
                '__wakeup',
                'getStatus',
                'getMessages',
                'getScheduleId',
                'getResource',
            ]
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->atLeastOnce())->method('getJobCode')->willReturn($jobCode);
        $schedule->expects($this->atLeastOnce())->method('getScheduledAt')->willReturn($dateScheduledAt);
        $schedule->expects($this->once())->method('tryLockJob')->willReturn(true);
        $schedule->expects(
            $this->any()
        )->method(
            'setStatus'
        )->with(
            $this->equalTo(\Magento\Cron\Model\Schedule::STATUS_MISSED)
        )->willReturnSelf();
        $schedule->expects($this->once())->method('setMessages')->with($this->equalTo($exceptionMessage));
        $schedule->expects($this->atLeastOnce())->method('getStatus')->willReturn(Schedule::STATUS_MISSED);
        $schedule->expects($this->atLeastOnce())->method('getMessages')->willReturn($exceptionMessage);
        $schedule->expects($this->once())->method('save');
        $schedule->expects($this->once())->method('getResource')->willReturn($this->scheduleResource);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->scheduleResource->expects($this->once())
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->once())
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        $this->appStateMock->expects($this->once())->method('getMode')->willReturn(State::MODE_DEVELOPER);

        $this->loggerMock->expects($this->once())->method('info')
            ->with('Cron Job test_job1 is missed at 2017-07-30 15:00:00');

        $this->_collection->addItem($schedule);

        $this->_config->expects(
            $this->exactly(2)
        )->method(
            'getJobs'
        )->willReturn(
            ['test_group' => ['test_job1' => ['test_data']]]
        );

        $scheduleMock = $this->getMockBuilder(\Magento\Cron\Model\Schedule::class)
            ->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $scheduleMock->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->atLeastOnce())->method('create')->willReturn($scheduleMock);

        $this->_observer->execute($this->observer);
    }

    /**
     * Test case catch exception if callback not exist
     */
    public function testDispatchExceptionNoCallback()
    {
        $jobName = 'test_job1';
        $exceptionMessage = 'No callbacks found for cron job ' . $jobName;
        $exception = new \Exception(__($exceptionMessage));

        $this->eventManager->expects($this->never())->method('dispatch');

        $dateScheduledAt = date('Y-m-d H:i:s', $this->time - 86400);
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            [
                'getJobCode',
                'tryLockJob',
                'getScheduledAt',
                'save',
                'setStatus',
                'setMessages',
                '__wakeup',
                'getStatus',
                'getResource'
            ]
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('test_job1');
        $schedule->expects($this->once())->method('getScheduledAt')->willReturn($dateScheduledAt);
        $schedule->expects($this->once())->method('tryLockJob')->willReturn(true);
        $schedule->expects(
            $this->once()
        )->method(
            'setStatus'
        )->with(
            $this->equalTo(\Magento\Cron\Model\Schedule::STATUS_ERROR)
        )->willReturnSelf();
        $schedule->expects($this->once())->method('setMessages')->with($this->equalTo($exceptionMessage));
        $schedule->expects($this->any())->method('getStatus')->willReturn(Schedule::STATUS_ERROR);
        $schedule->expects($this->once())->method('save');
        $schedule->expects($this->once())->method('getResource')->willReturn($this->scheduleResource);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->scheduleResource->expects($this->once())
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->once())
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');
        $this->_collection->addItem($schedule);

        $this->loggerMock->expects($this->once())->method('critical')->with($exception);

        $jobConfig = ['test_group' => [$jobName => ['instance' => 'Some_Class']]];

        $this->_config->expects($this->exactly(2))->method('getJobs')->willReturn($jobConfig);

        $lastRun = $this->time + 10000000;
        $this->_cache->expects($this->any())->method('load')->willReturn($lastRun);

        $this->_scopeConfig->expects($this->any())
            ->method('getValue')
            ->willReturn($this->time + 86400);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $scheduleMock->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->once())->method('create')->willReturn($scheduleMock);

        $this->_observer->execute($this->observer);
    }

    /**
     * Test case catch exception if callback is not callable or throws exception
     *
     * @param string $cronJobType
     * @param mixed $cronJobObject
     * @param string $exceptionMessage
     * @param int $saveCalls
     * @param int $dispatchCalls
     * @param \Exception $exception
     *
     * @dataProvider dispatchExceptionInCallbackDataProvider
     */
    public function testDispatchExceptionInCallback(
        $cronJobType,
        $cronJobObject,
        $exceptionMessage,
        $saveCalls,
        $dispatchCalls,
        $exception
    ) {
        $jobConfig = [
            'test_group' => [
                'test_job1' => ['instance' => $cronJobType, 'method' => 'execute'],
            ],
        ];

        $this->eventManager->expects($this->exactly($dispatchCalls))
            ->method('dispatch')
            ->with('cron_job_run', ['job_name' => 'cron/test_group/test_job1']);

        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');

        $dateScheduledAt = date('Y-m-d H:i:s', $this->time - 86400);
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            [
                'getJobCode',
                'tryLockJob',
                'getScheduledAt',
                'save',
                'setStatus',
                'setMessages',
                '__wakeup',
                'getStatus',
                'getResource'
            ]
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('test_job1');
        $schedule->expects($this->once())->method('getScheduledAt')->willReturn($dateScheduledAt);
        $schedule->expects($this->once())->method('tryLockJob')->willReturn(true);
        $schedule->expects($this->once())
            ->method('setStatus')
            ->with($this->equalTo(\Magento\Cron\Model\Schedule::STATUS_ERROR))
            ->willReturnSelf();
        $schedule->expects($this->once())->method('setMessages')->with($this->equalTo($exceptionMessage));
        $schedule->expects($this->any())->method('getStatus')->willReturn(Schedule::STATUS_ERROR);
        $schedule->expects($this->exactly($saveCalls))->method('save');
        $schedule->expects($this->exactly($saveCalls))->method('getResource')->willReturn($this->scheduleResource);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->scheduleResource->expects($this->exactly($saveCalls))
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->exactly($saveCalls))
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        $this->loggerMock->expects($this->once())->method('critical')->with($exception);

        $this->_collection->addItem($schedule);

        $this->_config->expects($this->exactly(2))->method('getJobs')->willReturn($jobConfig);

        $lastRun = $this->time + 10000000;
        $this->_cache->expects($this->any())->method('load')->willReturn($lastRun);
        $this->_scopeConfig->expects($this->any())
            ->method('getValue')
            ->willReturn($this->time + 86400);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $scheduleMock->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->once())->method('create')->willReturn($scheduleMock);
        $this->_objectManager
            ->expects($this->once())
            ->method('create')
            ->with($this->equalTo($cronJobType))
            ->willReturn($cronJobObject);

        $this->_observer->execute($this->observer);
    }

    /**
     * @return array
     */
    public function dispatchExceptionInCallbackDataProvider()
    {
        $throwable = new \TypeError();
        return [
            'non-callable callback' => [
                'Not_Existed_Class',
                '',
                'Invalid callback: Not_Existed_Class::execute can\'t be called',
                1,
                0,
                new \Exception(__('Invalid callback: Not_Existed_Class::execute can\'t be called'))
            ],
            'exception in execution' => [
                'CronJobException',
                new \Magento\Cron\Test\Unit\Model\CronJobException(),
                'Test exception',
                2,
                1,
                new \Exception(__('Test exception'))
            ],
            'throwable in execution' => [
                'CronJobException',
                new \Magento\Cron\Test\Unit\Model\CronJobException(
                    $throwable
                ),
                'Error when running a cron job',
                2,
                1,
                new \RuntimeException(
                    'Error when running a cron job',
                    0,
                    $throwable
                )
            ],
        ];
    }

    /**
     * Test case, successfully run job
     */
    public function testDispatchRunJob()
    {
        $jobConfig = [
            'test_group' => ['test_job1' => ['instance' => 'CronJob', 'method' => 'execute']],
        ];
        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('cron_job_run', ['job_name' => 'cron/test_group/test_job1']);

        $dateScheduledAt = date('Y-m-d H:i:s', $this->time - 86400);
        $scheduleMethods = [
            'getJobCode',
            'tryLockJob',
            'getScheduledAt',
            'save',
            'setStatus',
            'setMessages',
            'setExecutedAt',
            'setFinishedAt',
            '__wakeup',
            'getResource',
        ];
        /** @var \Magento\Cron\Model\Schedule|\PHPUnit\Framework\MockObject\MockObject $schedule */
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            $scheduleMethods
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('test_job1');
        $schedule->expects($this->atLeastOnce())->method('getScheduledAt')->willReturn($dateScheduledAt);
        $schedule->expects($this->atLeastOnce())->method('tryLockJob')->willReturn(true);
        $schedule->expects($this->any())->method('setFinishedAt')->willReturnSelf();
        $schedule->expects($this->exactly(2))->method('getResource')->willReturn($this->scheduleResource);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->scheduleResource->expects($this->exactly(2))
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        // cron start to execute some job
        $schedule->expects($this->any())->method('setExecutedAt')->willReturnSelf();
        $schedule->expects($this->atLeastOnce())->method('save');

        // cron end execute some job
        $schedule->expects(
            $this->atLeastOnce()
        )->method(
            'setStatus'
        )->with(
            $this->equalTo(\Magento\Cron\Model\Schedule::STATUS_SUCCESS)
        )->willReturnSelf();

        $schedule->expects($this->at(8))->method('save');

        $this->_collection->addItem($schedule);

        $this->_config->expects($this->exactly(2))->method('getJobs')->willReturn($jobConfig);

        $lastRun = $this->time + 10000000;
        $this->_cache->expects($this->any())->method('load')->willReturn($lastRun);
        $this->_scopeConfig->expects($this->any())
            ->method('getValue')
            ->willReturn($this->time + 86400);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $this->_scheduleFactory->expects($this->once())->method('create')->willReturn($scheduleMock);

        $testCronJob = $this->getMockBuilder('CronJob')->setMethods(['execute'])->getMock();
        $testCronJob->expects($this->atLeastOnce())->method('execute')->with($schedule);

        $this->_objectManager->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $this->equalTo('CronJob')
        )->willReturn($testCronJob);

        $this->_observer->execute($this->observer);
    }

    /**
     * Testing _generate(), iterate over saved cron jobs
     */
    public function testDispatchNotGenerate()
    {
        $jobConfig = [
            'test_group' => ['test_job1' => ['instance' => 'CronJob', 'method' => 'execute']],
        ];

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->_config->expects($this->at(0))->method('getJobs')->willReturn($jobConfig);
        $this->_config->expects(
            $this->at(1)
        )->method(
            'getJobs'
        )->willReturn(['test_group' => []]);
        $this->_config->expects($this->at(2))->method('getJobs')->willReturn($jobConfig);
        $this->_config->expects($this->at(3))->method('getJobs')->willReturn($jobConfig);
        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');
        $this->_cache->expects(
            $this->at(0)
        )->method(
            'load'
        )->with(
            $this->equalTo(ProcessCronQueueObserver::CACHE_KEY_LAST_HISTORY_CLEANUP_AT . 'test_group')
        )->willReturn($this->time + 10000000);
        $this->_cache->expects(
            $this->at(1)
        )->method(
            'load'
        )->with(
            $this->equalTo(ProcessCronQueueObserver::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT . 'test_group')
        )->willReturn($this->time - 10000000);

        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn(0);

        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            ['getJobCode', 'getScheduledAt', '__wakeup']
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('job_code1');
        $schedule->expects($this->once())->method('getScheduledAt')->willReturn('* * * * *');

        $this->_collection->addItem(new \Magento\Framework\DataObject());
        $this->_collection->addItem($schedule);

        $this->_cache->expects($this->any())->method('save');

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $this->_scheduleFactory->expects($this->any())->method('create')->willReturn($scheduleMock);

        $this->_scheduleFactory->expects($this->any())->method('create')->willReturn($schedule);

        $this->_observer->execute($this->observer);
    }

    /**
     * Testing _generate(), iterate over saved cron jobs and generate jobs
     */
    public function testDispatchGenerate()
    {
        $jobConfig = [
            'default' => [
                'test_job1' => [
                    'instance' => 'CronJob',
                    'method' => 'execute',
                ],
            ],
        ];

        $jobs = [
            'default' => [
                'job1' => ['config_path' => 'test/path'],
                'job2' => ['schedule' => ''],
                'job3' => ['schedule' => '* * * * *'],
            ],
        ];
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->_config->expects($this->at(0))->method('getJobs')->willReturn($jobConfig);
        $this->_config->expects($this->at(1))->method('getJobs')->willReturn($jobs);
        $this->_config->expects($this->at(2))->method('getJobs')->willReturn($jobs);
        $this->_config->expects($this->at(3))->method('getJobs')->willReturn($jobs);
        $this->_request->expects($this->any())->method('getParam')->willReturn('default');
        $this->_cache->expects(
            $this->at(0)
        )->method(
            'load'
        )->with(
            $this->equalTo(ProcessCronQueueObserver::CACHE_KEY_LAST_HISTORY_CLEANUP_AT . 'default')
        )->willReturn($this->time + 10000000);
        $this->_cache->expects(
            $this->at(1)
        )->method(
            'load'
        )->with(
            $this->equalTo(ProcessCronQueueObserver::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT . 'default')
        )->willReturn($this->time - 10000000);

        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturnMap(
            [
                [
                    'system/cron/default/schedule_generate_every',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    null,
                    0
                ],
                [
                    'system/cron/default/schedule_ahead_for',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    null,
                    2
                ]
            ]
        );

        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(
            ['getJobCode', 'save', 'getScheduledAt', 'unsScheduleId', 'trySchedule', 'getCollection', 'getResource']
        )->disableOriginalConstructor()->getMock();
        $schedule->expects($this->any())->method('getJobCode')->willReturn('job_code1');
        $schedule->expects($this->once())->method('getScheduledAt')->willReturn('* * * * *');
        $schedule->expects($this->any())->method('unsScheduleId')->willReturnSelf();
        $schedule->expects($this->any())->method('trySchedule')->willReturnSelf();
        $schedule->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $schedule->expects($this->atLeastOnce())->method('save')->willReturnSelf();
        $schedule->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);

        $this->_collection->addItem(new \Magento\Framework\DataObject());
        $this->_collection->addItem($schedule);

        $this->_cache->expects($this->any())->method('save');

        $this->_scheduleFactory->expects($this->any())->method('create')->willReturn($schedule);

        $this->_observer->execute($this->observer);
    }

    /**
     * Test case without saved cron jobs in data base
     */
    public function testDispatchCleanup()
    {
        $jobConfig = [
            'test_group' => ['test_job1' => ['instance' => 'CronJob', 'method' => 'execute']],
        ];

        $this->eventManager->expects($this->never())->method('dispatch');
        $dateExecutedAt = date('Y-m-d H:i:s', $this->time - 86400);
        $schedule = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->setMethods(
            ['getExecutedAt', 'getStatus', 'delete', '__wakeup']
        )->getMock();
        $schedule->expects($this->any())->method('getExecutedAt')->willReturn($dateExecutedAt);
        $schedule->expects($this->any())->method('getStatus')->willReturn('success');
        $this->_request->expects($this->any())->method('getParam')->willReturn('test_group');
        $this->_collection->addItem($schedule);

        $this->_config->expects($this->atLeastOnce())->method('getJobs')->willReturn($jobConfig);

        $this->_cache->expects($this->at(0))->method('load')->willReturn($this->time + 10000000);
        $this->_cache->expects($this->at(1))->method('load')->willReturn($this->time - 10000000);

        $this->_scopeConfig->expects($this->any())->method('getValue')->willReturn(0);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $this->_scheduleFactory->expects($this->at(0))->method('create')->willReturn($scheduleMock);

        $collection = $this->getMockBuilder(
            \Magento\Cron\Model\ResourceModel\Schedule\Collection::class
        )->setMethods(
            ['addFieldToFilter', 'load', '__wakeup']
        )->disableOriginalConstructor()->getMock();
        $collection->expects($this->any())->method('addFieldToFilter')->willReturnSelf();
        $collection->expects($this->any())->method('load')->willReturnSelf();
        $collection->addItem($schedule);

        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->setMethods(['getCollection', 'getResource'])->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($collection);
        $scheduleMock->expects($this->any())->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->any())->method('create')->willReturn($scheduleMock);

        $this->_observer->execute($this->observer);
    }

    public function testMissedJobsCleanedInTime()
    {
        $tableName = 'cron_schedule';

        $this->eventManager->expects($this->never())->method('dispatch');

        /* 1. Initialize dependencies of _cleanup() method which is called first */
        $scheduleMock = $this->getMockBuilder(
            \Magento\Cron\Model\Schedule::class
        )->disableOriginalConstructor()->getMock();
        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        //get configuration value CACHE_KEY_LAST_HISTORY_CLEANUP_AT in the "_cleanup()"
        $this->_cache->expects($this->at(0))->method('load')->willReturn($this->time - 10000000);

        /* 2. Initialize dependencies of _generate() method which is called second */
        $jobConfig = [
            'test_group' => ['test_job1' => ['instance' => 'CronJob', 'method' => 'execute']],
        ];
        //get configuration value CACHE_KEY_LAST_HISTORY_CLEANUP_AT in the "_generate()"
        $this->_cache->expects($this->at(2))->method('load')->willReturn($this->time + 10000000);
        $this->_scheduleFactory->expects($this->at(2))->method('create')->willReturn($scheduleMock);

        $this->_config->expects($this->atLeastOnce())->method('getJobs')->willReturn($jobConfig);

        $this->_scopeConfig->expects($this->any())->method('getValue')
            ->willReturnMap(
                [
                    ['system/cron/test_group/use_separate_process', 0],
                    ['system/cron/test_group/history_cleanup_every', 10],
                    ['system/cron/test_group/schedule_lifetime', 2*24*60],
                    ['system/cron/test_group/history_success_lifetime', 0],
                    ['system/cron/test_group/history_failure_lifetime', 0],
                    ['system/cron/test_group/schedule_generate_every', 0],
                ]
            );

        $this->_collection->expects($this->any())->method('addFieldToFilter')->willReturnSelf();
        $this->_collection->expects($this->any())->method('load')->willReturnSelf();

        $scheduleMock->expects($this->any())->method('getCollection')->willReturn($this->_collection);
        $scheduleMock->expects($this->exactly(9))->method('getResource')->willReturn($this->scheduleResource);
        $this->_scheduleFactory->expects($this->exactly(10))->method('create')->willReturn($scheduleMock);

        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $connectionMock->expects($this->exactly(5))
            ->method('delete')
            ->withConsecutive(
                [$tableName, ['status = ?' => 'pending', 'job_code in (?)' => ['test_job1']]],
                [$tableName, ['status = ?' => 'success', 'job_code in (?)' => ['test_job1'], 'created_at < ?' => null]],
                [$tableName, ['status = ?' => 'missed', 'job_code in (?)' => ['test_job1'], 'created_at < ?' => null]],
                [$tableName, ['status = ?' => 'error', 'job_code in (?)' => ['test_job1'], 'created_at < ?' => null]],
                [$tableName, ['status = ?' => 'pending', 'job_code in (?)' => ['test_job1'], 'created_at < ?' => null]]
            )
            ->willReturn(1);

        $this->scheduleResource->expects($this->exactly(5))
            ->method('getTable')
            ->with($tableName)
            ->willReturn($tableName);
        $this->scheduleResource->expects($this->exactly(14))
            ->method('getConnection')
            ->willReturn($connectionMock);

        $this->retrierMock->expects($this->exactly(5))
            ->method('execute')
            ->willReturnCallback(
                function ($callback) {
                    return $callback();
                }
            );

        $this->_observer->execute($this->observer);
    }
}
