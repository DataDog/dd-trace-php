<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */


namespace Magento\Framework\Crontab\Test\Unit;

use Magento\Framework\Crontab\CrontabManager;
use Magento\Framework\Crontab\CrontabManagerInterface;
use Magento\Framework\ShellInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\DriverPool;

/**
 * Tests crontab manager functionality.
 */
class CrontabManagerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ShellInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $shellMock;

    /**
     * @var Filesystem|\PHPUnit\Framework\MockObject\MockObject
     */
    private $filesystemMock;

    /**
     * @var CrontabManager
     */
    private $crontabManager;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->shellMock = $this->getMockBuilder(ShellInterface::class)
            ->getMockForAbstractClass();
        $this->filesystemMock = $this->getMockBuilder(Filesystem::class)
            ->disableOriginalClone()
            ->disableOriginalConstructor()
            ->getMock();

        $this->crontabManager = new CrontabManager($this->shellMock, $this->filesystemMock);
    }

    /**
     * @return void
     */
    public function testGetTasksNoCrontab()
    {
        $exception = new \Exception('crontab: no crontab for user');
        $localizedException = new LocalizedException(new Phrase('Some error'), $exception);

        $this->shellMock->expects($this->once())
            ->method('execute')
            ->with('crontab -l 2>/dev/null', [])
            ->willThrowException($localizedException);

        $this->assertEquals([], $this->crontabManager->getTasks());
    }

    /**
     * @param string $content
     * @param array $tasks
     * @return void
     * @dataProvider getTasksDataProvider
     */
    public function testGetTasks($content, $tasks)
    {
        $this->shellMock->expects($this->once())
            ->method('execute')
            ->with('crontab -l 2>/dev/null', [])
            ->willReturn($content);

        $this->assertEquals($tasks, $this->crontabManager->getTasks());
    }

    /**
     * @return array
     */
    public function getTasksDataProvider()
    {
        return [
            [
                'content' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * /bin/php /var/www/magento/bin/magento cron:run' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
                'tasks' => ['* * * * * /bin/php /var/www/magento/bin/magento cron:run'],
            ],
            [
                'content' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * /bin/php /var/www/magento/bin/magento cron:run' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
                'tasks' => [
                    '* * * * * /bin/php /var/www/magento/bin/magento cron:run',
                ],
            ],
            [
                'content' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL,
                'tasks' => [],
            ],
            [
                'content' => '',
                'tasks' => [],
            ],
        ];
    }

    /**
     * @return void
     */
    public function testRemoveTasksWithException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Shell error');

        $exception = new \Exception('Shell error');
        $localizedException = new LocalizedException(new Phrase('Some error'), $exception);

        $this->shellMock->expects($this->at(0))
            ->method('execute')
            ->with('crontab -l 2>/dev/null', [])
            ->willReturn('');

        $this->shellMock->expects($this->at(1))
            ->method('execute')
            ->with('echo "" | crontab -', [])
            ->willThrowException($localizedException);

        $this->crontabManager->removeTasks();
    }

    /**
     * @param string $contentBefore
     * @param string $contentAfter
     * @return void
     * @dataProvider removeTasksDataProvider
     */
    public function testRemoveTasks($contentBefore, $contentAfter)
    {
        $this->shellMock->expects($this->at(0))
            ->method('execute')
            ->with('crontab -l 2>/dev/null', [])
            ->willReturn($contentBefore);

        $this->shellMock->expects($this->at(1))
            ->method('execute')
            ->with('echo "' . $contentAfter . '" | crontab -', []);

        $this->crontabManager->removeTasks();
    }

    /**
     * @return array
     */
    public function removeTasksDataProvider()
    {
        return [
            [
                'contentBefore' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * /bin/php /var/www/magento/bin/magento cron:run' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
                'contentAfter' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
            ],
            [
                'contentBefore' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * /bin/php /var/www/magento/bin/magento cron:run' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
                'contentAfter' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
            ],
            [
                'contentBefore' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL,
                'contentAfter' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
            ],
            [
                'contentBefore' => '',
                'contentAfter' => ''
            ],
        ];
    }

    /**
     * @return void
     */
    public function testSaveTasksWithEmptyTasksList()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The list of tasks is empty. Add tasks and try again.');

        $baseDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $baseDirMock->expects($this->never())
            ->method('getAbsolutePath')
            ->willReturn('/var/www/magento2/');
        $logDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $logDirMock->expects($this->never())
            ->method('getAbsolutePath');

        $this->filesystemMock->expects($this->any())
            ->method('getDirectoryRead')
            ->willReturnMap([
                [DirectoryList::ROOT, DriverPool::FILE, $baseDirMock],
                [DirectoryList::LOG, DriverPool::FILE, $logDirMock],
            ]);

        $this->crontabManager->saveTasks([]);
    }

    /**
     * @return void
     */
    public function testSaveTasksWithoutCommand()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The command shouldn\'t be empty. Enter and try again.');

        $baseDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $baseDirMock->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn('/var/www/magento2/');
        $logDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $logDirMock->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn('/var/www/magento2/var/log/');

        $this->filesystemMock->expects($this->any())
            ->method('getDirectoryRead')
            ->willReturnMap([
                [DirectoryList::ROOT, DriverPool::FILE, $baseDirMock],
                [DirectoryList::LOG, DriverPool::FILE, $logDirMock],
            ]);

        $this->crontabManager->saveTasks([
            'myCron' => ['expression' => '* * * * *']
        ]);
    }

    /**
     * @param array $tasks
     * @param string $content
     * @param string $contentToSave
     * @return void
     * @dataProvider saveTasksDataProvider
     */
    public function testSaveTasks($tasks, $content, $contentToSave)
    {
        $baseDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $baseDirMock->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn('/var/www/magento2/');
        $logDirMock = $this->getMockBuilder(ReadInterface::class)
            ->getMockForAbstractClass();
        $logDirMock->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn('/var/www/magento2/var/log/');

        $this->filesystemMock->expects($this->any())
            ->method('getDirectoryRead')
            ->willReturnMap([
                [DirectoryList::ROOT, DriverPool::FILE, $baseDirMock],
                [DirectoryList::LOG, DriverPool::FILE, $logDirMock],
            ]);

        $this->shellMock->expects($this->at(0))
            ->method('execute')
            ->with('crontab -l 2>/dev/null', [])
            ->willReturn($content);

        $this->shellMock->expects($this->at(1))
            ->method('execute')
            ->with('echo "' . $contentToSave . '" | crontab -', []);

        $this->crontabManager->saveTasks($tasks);
    }

    /**
     * @return array
     */
    public function saveTasksDataProvider()
    {
        $content = '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
            . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
            . '* * * * * /bin/php /var/www/magento/bin/magento cron:run' . PHP_EOL
            . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL;

        return [
            [
                'tasks' => [
                    ['expression' => '* * * * *', 'command' => 'run.php']
                ],
                'content' => $content,
                'contentToSave' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * ' . PHP_BINARY . ' run.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
            ],
            [
                'tasks' => [
                    ['expression' => '1 2 3 4 5', 'command' => 'run.php']
                ],
                'content' => $content,
                'contentToSave' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '1 2 3 4 5 ' . PHP_BINARY . ' run.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
            ],
            [
                'tasks' => [
                    ['command' => '{magentoRoot}run.php >> {magentoLog}cron.log']
                ],
                'content' => $content,
                'contentToSave' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * ' . PHP_BINARY . ' /var/www/magento2/run.php >>'
                    . ' /var/www/magento2/var/log/cron.log' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
            ],
            [
                'tasks' => [
                    ['command' => '{magentoRoot}run.php % cron:run | grep -v "Ran \'jobs\' by schedule"']
                ],
                'content' => $content,
                'contentToSave' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * ' . PHP_BINARY . ' /var/www/magento2/run.php'
                    . ' %% cron:run | grep -v \"Ran \'jobs\' by schedule\"' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
            ],
            [
                'tasks' => [
                    ['command' => '{magentoRoot}run.php % cron:run | grep -v "Ran \'jobs\' by schedule"']
                ],
                'content' => '* * * * * /bin/php /var/www/cron.php',
                'contentToSave' => '* * * * * /bin/php /var/www/cron.php' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_START . ' ' . hash("sha256", BP) . PHP_EOL
                    . '* * * * * ' . PHP_BINARY . ' /var/www/magento2/run.php'
                    . ' %% cron:run | grep -v \"Ran \'jobs\' by schedule\"' . PHP_EOL
                    . CrontabManagerInterface::TASKS_BLOCK_END . ' ' . hash("sha256", BP) . PHP_EOL,
            ],
        ];
    }
}
