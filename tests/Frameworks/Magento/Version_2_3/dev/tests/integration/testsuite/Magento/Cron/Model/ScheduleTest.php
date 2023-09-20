<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cron\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use \Magento\TestFramework\Helper\Bootstrap;

/**
 * Test \Magento\Cron\Model\Schedule
 *
 * @magentoDbIsolation enabled
 */
class ScheduleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ScheduleFactory
     */
    private $scheduleFactory;

    /**
     * @var DateTime
     */
    protected $dateTime;

    protected function setUp(): void
    {
        $this->dateTime = Bootstrap::getObjectManager()->create(DateTime::class);
        $this->scheduleFactory = Bootstrap::getObjectManager()->create(ScheduleFactory::class);
    }

    /**
     * If there are no currently locked jobs, locking one of them should succeed
     */
    public function testTryLockJobNoLockedJobsSucceeds()
    {
        for ($i = 1; $i < 6; $i++) {
            $this->createSchedule("test_job", Schedule::STATUS_PENDING, 60 * $i);
        }
        $schedule = $this->createSchedule("test_job", Schedule::STATUS_PENDING);

        $this->assertTrue($schedule->tryLockJob());
    }

    /**
     * If the job is already locked, attempting to lock it again should fail
     */
    public function testTryLockJobAlreadyLockedFails()
    {
        $schedule = $this->createSchedule("test_job", Schedule::STATUS_RUNNING);

        $this->assertFalse($schedule->tryLockJob());
    }

    /**
     * If the job is already locked but lock time less than 1 day ago, attempting to lock it again should fail
     */
    public function testTryLockJobAlreadyLockedSucceeds()
    {
        $offsetInThePast = 2*24*60*60;

        $oldSchedule = $this->scheduleFactory->create()
            ->setCronExpr("* * * * *")
            ->setJobCode("test_job")
            ->setStatus(Schedule::STATUS_RUNNING)
            ->setCreatedAt(strftime('%Y-%m-%d %H:%M:%S', $this->dateTime->gmtTimestamp() - $offsetInThePast))
            ->setScheduledAt(strftime('%Y-%m-%d %H:%M', $this->dateTime->gmtTimestamp() - $offsetInThePast + 60))
            ->setExecutedAt(strftime('%Y-%m-%d %H:%M', $this->dateTime->gmtTimestamp() - $offsetInThePast + 61));
        $oldSchedule->save();

        $schedule = $this->createSchedule("test_job", Schedule::STATUS_PENDING);

        $this->assertTrue($schedule->tryLockJob());
    }

    /**
     * If there's a job already has running status, should  be able to set this status for another job
     */
    public function testTryLockJobOtherLockedFails()
    {
        $this->createSchedule("test_job", Schedule::STATUS_RUNNING);
        $schedule = $this->createSchedule("test_job", Schedule::STATUS_PENDING, 60);

        $this->assertTrue($schedule->tryLockJob());
    }

    /**
     * Should be able to lock a job if a job with a different code is locked
     */
    public function testTryLockJobDifferentJobLocked()
    {
        $this->createSchedule("test_job_other", Schedule::STATUS_RUNNING);
        $schedule = $this->createSchedule("test_job", Schedule::STATUS_PENDING);

        $this->assertTrue($schedule->tryLockJob());
    }

    /**
     * Creates a schedule with the given job code, status, and schedule time offset
     *
     * @param string $jobCode
     * @param string $status
     * @param int $timeOffset
     * @return Schedule
     */
    private function createSchedule($jobCode, $status, $timeOffset = 0)
    {
        $schedule = $this->scheduleFactory->create()
            ->setCronExpr("* * * * *")
            ->setJobCode($jobCode)
            ->setStatus($status)
            ->setCreatedAt(strftime('%Y-%m-%d %H:%M:%S', $this->dateTime->gmtTimestamp()))
            ->setScheduledAt(strftime('%Y-%m-%d %H:%M', $this->dateTime->gmtTimestamp() + $timeOffset));
        $schedule->save();

        return $schedule;
    }
}
