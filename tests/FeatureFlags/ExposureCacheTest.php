<?php

namespace DDTrace\Tests\FeatureFlags;

require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/LRUCache.php';
require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/ExposureCache.php';

use DDTrace\FeatureFlags\ExposureCache;
use DDTrace\Tests\Common\BaseTestCase;

/**
 * Tests for ExposureCache, matching the canonical Java LRUExposureCacheTest.
 */
final class ExposureCacheTest extends BaseTestCase
{
    public function testAddingElements()
    {
        $cache = new ExposureCache(5);
        $added = $cache->add('flag', 'subject', 'variant', 'allocation');

        $this->assertTrue($added);
        $this->assertSame(1, $cache->size());
    }

    public function testAddingDuplicateEventsReturnsFalse()
    {
        $cache = new ExposureCache(5);
        $cache->add('flag', 'subject', 'variant', 'allocation');
        $duplicateAdded = $cache->add('flag', 'subject', 'variant', 'allocation');

        $this->assertFalse($duplicateAdded);
        $this->assertSame(1, $cache->size());
    }

    public function testAddingEventsWithSameKeyButDifferentDetailsUpdatesCache()
    {
        $cache = new ExposureCache(5);
        $added1 = $cache->add('flag', 'subject', 'variant1', 'allocation1');
        $added2 = $cache->add('flag', 'subject', 'variant2', 'allocation2');

        $retrieved = $cache->get('flag', 'subject');

        $this->assertTrue($added1);
        $this->assertTrue($added2);
        $this->assertSame(1, $cache->size());
        $this->assertSame('variant2', $retrieved[0]);
        $this->assertSame('allocation2', $retrieved[1]);
    }

    public function testLRUEvictionWhenCapacityExceeded()
    {
        $cache = new ExposureCache(2);
        $cache->add('flag1', 'subject1', 'variant1', 'allocation1');
        $cache->add('flag2', 'subject2', 'variant2', 'allocation2');
        $cache->add('flag3', 'subject3', 'variant3', 'allocation3');

        $this->assertSame(2, $cache->size());
        $this->assertNull($cache->get('flag1', 'subject1'), 'event1 should be evicted');

        $retrieved3 = $cache->get('flag3', 'subject3');
        $this->assertNotNull($retrieved3);
        $this->assertSame('variant3', $retrieved3[0]);
        $this->assertSame('allocation3', $retrieved3[1]);
    }

    public function testSingleCapacityCache()
    {
        $cache = new ExposureCache(1);
        $cache->add('flag1', 'subject1', 'variant1', 'allocation1');
        $cache->add('flag2', 'subject2', 'variant2', 'allocation2');

        $this->assertSame(1, $cache->size());
    }

    public function testZeroCapacityCache()
    {
        $cache = new ExposureCache(0);
        $added = $cache->add('flag', 'subject', 'variant', 'allocation');

        $this->assertTrue($added);
        $this->assertSame(0, $cache->size());
    }

    public function testEmptyCacheSize()
    {
        $cache = new ExposureCache(5);
        $this->assertSame(0, $cache->size());
    }

    public function testMultipleAdditionsWithSameFlagDifferentSubjects()
    {
        $cache = new ExposureCache(10);
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $cache->add('flag', "subject{$i}", 'variant', 'allocation');
        }

        $this->assertSame([true, true, true, true, true], $results);
        $this->assertSame(5, $cache->size());
    }

    public function testMultipleAdditionsWithSameSubjectDifferentFlags()
    {
        $cache = new ExposureCache(10);
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $cache->add("flag{$i}", 'subject', 'variant', 'allocation');
        }

        $this->assertSame([true, true, true, true, true], $results);
        $this->assertSame(5, $cache->size());
    }

    public function testKeyEqualityWithNullValues()
    {
        $cache = new ExposureCache(5);
        $cache->add('', '', 'variant', 'allocation');
        $duplicateAdded = $cache->add('', '', 'variant', 'allocation');

        $this->assertFalse($duplicateAdded);
        $this->assertSame(1, $cache->size());
    }

    public function testUpdatingExistingKeyMaintainsLRUPosition()
    {
        $cache = new ExposureCache(3);
        $cache->add('flag1', 'subject1', 'variant1', 'allocation1');
        $cache->add('flag2', 'subject2', 'variant2', 'allocation2');
        $cache->add('flag3', 'subject3', 'variant3', 'allocation3');
        // Update event1 with new details — moves it to most recent
        $cache->add('flag1', 'subject1', 'variant2', 'allocation2');
        // Should evict event2, not event1
        $cache->add('flag4', 'subject4', 'variant4', 'allocation4');

        $this->assertSame(3, $cache->size());

        $retrieved1 = $cache->get('flag1', 'subject1');
        $this->assertNotNull($retrieved1, 'event1 should be present (was updated)');
        $this->assertSame('variant2', $retrieved1[0]);
        $this->assertSame('allocation2', $retrieved1[1]);

        $this->assertNull($cache->get('flag2', 'subject2'), 'event2 should be evicted');

        $retrieved4 = $cache->get('flag4', 'subject4');
        $this->assertNotNull($retrieved4);
        $this->assertSame('variant4', $retrieved4[0]);
    }

    public function testDuplicateExposureKeepsSubjectHotInLRUOrder()
    {
        $cache = new ExposureCache(3);
        // Fill cache
        $added1 = $cache->add('flag1', 'subject1', 'variant1', 'allocation1');
        $added2 = $cache->add('flag2', 'subject2', 'variant2', 'allocation2');
        $added3 = $cache->add('flag3', 'subject3', 'variant3', 'allocation3');

        // Duplicate exposure for subject1: should NOT change size, but SHOULD bump recency
        $duplicateAdded = $cache->add('flag1', 'subject1', 'variant1', 'allocation1');

        // Now push over capacity: the LRU entry (event2) should be evicted, not event1
        $added4 = $cache->add('flag4', 'subject4', 'variant4', 'allocation4');

        $this->assertTrue($added1);
        $this->assertTrue($added2);
        $this->assertTrue($added3);
        $this->assertFalse($duplicateAdded, 'exact duplicate should return false');
        $this->assertTrue($added4);

        $this->assertSame(3, $cache->size());

        // Hot subject1 should still be present (duplicate bumped its recency)
        $retrieved1 = $cache->get('flag1', 'subject1');
        $this->assertNotNull($retrieved1, 'hot subject1 should still be present');
        $this->assertSame('variant1', $retrieved1[0]);
        $this->assertSame('allocation1', $retrieved1[1]);

        // subject2 should be evicted (it was LRU)
        $this->assertNull($cache->get('flag2', 'subject2'), 'subject2 should be evicted');

        // Newest subject4 should be present
        $this->assertNotNull($cache->get('flag4', 'subject4'));
    }
}
