<?php

namespace Drupal\Tests\aggregator\Functional;

use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Update feeds on cron.
 *
 * @group aggregator
 * @group legacy
 */
class AggregatorCronTest extends AggregatorTestBase {

  use CronRunTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Adds feeds and updates them via cron process.
   */
  public function testCron() {
    // Create feed and test basic updating on cron.
    $this->createSampleNodes();
    $feed = $this->createFeed();
    $count_query = \Drupal::entityQuery('aggregator_item')
      ->accessCheck(FALSE)
      ->condition('fid', $feed->id())
      ->count();

    $this->cronRun();
    $this->assertEquals(5, $count_query->execute());
    $this->deleteFeedItems($feed);
    $this->assertEquals(0, $count_query->execute());
    $this->cronRun();
    $this->assertEquals(5, $count_query->execute());

    // Test feed locking when queued for update.
    $this->deleteFeedItems($feed);
    $feed->setQueuedTime(REQUEST_TIME)->save();
    $this->cronRun();
    $this->assertEquals(0, $count_query->execute());
    $feed->setQueuedTime(0)->save();
    $this->cronRun();
    $this->assertEquals(5, $count_query->execute());
  }

}
