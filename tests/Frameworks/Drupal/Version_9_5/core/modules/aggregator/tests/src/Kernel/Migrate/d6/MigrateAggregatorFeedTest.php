<?php

namespace Drupal\Tests\aggregator\Kernel\Migrate\d6;

use Drupal\aggregator\Entity\Feed;

/**
 * Tests migration of aggregator feeds.
 *
 * @group aggregator
 * @group legacy
 */
class MigrateAggregatorFeedTest extends MigrateDrupal6TestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('aggregator_feed');
    $this->executeMigration('d6_aggregator_feed');
  }

  /**
   * Tests migration of aggregator feeds.
   */
  public function testAggregatorFeedImport() {
    /** @var \Drupal\aggregator\Entity\Feed $feed */
    $feed = Feed::load(5);
    $this->assertSame('Know Your Meme', $feed->title->value);
    $this->assertSame('en', $feed->language()->getId());
    $this->assertSame('http://knowyourmeme.com/newsfeed.rss', $feed->url->value);
    $this->assertSame('900', $feed->refresh->value);
    $this->assertSame('1387659487', $feed->checked->value);
    $this->assertSame('0', $feed->queued->value);
    $this->assertSame('http://knowyourmeme.com', $feed->link->value);
    $this->assertSame('New items added to the News Feed', $feed->description->value);
    $this->assertSame('http://b.thumbs.redditmedia.com/harEHsUUZVajabtC.png', $feed->image->value);
    $this->assertSame('"213cc1365b96c310e92053c5551f0504"', $feed->etag->value);
    $this->assertSame('0', $feed->modified->value);
  }

}
