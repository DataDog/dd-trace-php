<?php

namespace DDTrace\Tests\FeatureFlags;

require_once __DIR__ . '/../../src/DDTrace/FeatureFlags/LRUCache.php';

use DDTrace\FeatureFlags\LRUCache;
use DDTrace\Tests\Common\BaseTestCase;

final class LRUCacheTest extends BaseTestCase
{
    public function testGetMissReturnsNull()
    {
        $cache = new LRUCache(10);
        $this->assertNull($cache->get('nonexistent'));
    }

    public function testSetAndGet()
    {
        $cache = new LRUCache(10);
        $cache->set('key1', 'value1');
        $this->assertSame('value1', $cache->get('key1'));
    }

    public function testEviction()
    {
        $cache = new LRUCache(3);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Cache is full; inserting a 4th should evict 'a' (least recently used)
        $cache->set('d', 4);

        $this->assertNull($cache->get('a'), 'Oldest entry should be evicted');
        $this->assertSame(2, $cache->get('b'));
        $this->assertSame(3, $cache->get('c'));
        $this->assertSame(4, $cache->get('d'));
    }

    public function testAccessPromotesEntry()
    {
        $cache = new LRUCache(3);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Access 'a' to promote it — now 'b' is the least recently used
        $cache->get('a');

        $cache->set('d', 4);

        $this->assertNull($cache->get('b'), "'b' should be evicted as LRU");
        $this->assertSame(1, $cache->get('a'), "'a' should survive after promotion");
        $this->assertSame(3, $cache->get('c'));
        $this->assertSame(4, $cache->get('d'));
    }

    public function testUpdateExistingKey()
    {
        $cache = new LRUCache(3);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Update 'a' — this should promote it to most recently used
        $cache->set('a', 100);

        $this->assertSame(100, $cache->get('a'), 'Value should be updated');

        // Now 'b' is LRU. Adding a new entry should evict 'b'.
        $cache->set('d', 4);
        $this->assertNull($cache->get('b'), "'b' should be evicted");
        $this->assertSame(100, $cache->get('a'));
    }

    public function testClear()
    {
        $cache = new LRUCache(10);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();

        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
    }

    public function testEvictionOrder()
    {
        $cache = new LRUCache(4);

        // Insert a, b, c, d in order — LRU order: a, b, c, d
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);
        $cache->set('d', 4);

        // Access 'b' and 'a' — LRU order is now: c, d, b, a
        $cache->get('b');
        $cache->get('a');

        // Insert 'e' — should evict 'c' (the LRU) — order: d, b, a, e
        $cache->set('e', 5);
        $this->assertNull($cache->get('c'), "'c' should be evicted first");

        // Insert 'f' — should evict 'd' (now the LRU) — order: b, a, e, f
        $cache->set('f', 6);
        $this->assertNull($cache->get('d'), "'d' should be evicted");

        // b, a, e, f should still be present
        $this->assertSame(2, $cache->get('b'));
        $this->assertSame(1, $cache->get('a'));
        $this->assertSame(5, $cache->get('e'));
        $this->assertSame(6, $cache->get('f'));
    }

    public function testSizeOneCache()
    {
        $cache = new LRUCache(1);
        $cache->set('a', 1);
        $this->assertSame(1, $cache->get('a'));

        $cache->set('b', 2);
        $this->assertNull($cache->get('a'), 'Old entry should be evicted in size-1 cache');
        $this->assertSame(2, $cache->get('b'));
    }

    public function testPutReturnsOldValue()
    {
        $cache = new LRUCache(10);
        $old1 = $cache->put('a', 1);
        $old2 = $cache->put('a', 2);

        $this->assertNull($old1, 'First put should return null');
        $this->assertSame(1, $old2, 'Second put should return old value');
        $this->assertSame(2, $cache->get('a'));
    }

    public function testPutPromotesLRU()
    {
        $cache = new LRUCache(3);
        $cache->put('a', 1);
        $cache->put('b', 2);
        $cache->put('c', 3);

        // put 'a' again (same value) — should promote to most recent
        $cache->put('a', 1);

        // Adding 'd' should evict 'b' (LRU), not 'a'
        $cache->put('d', 4);
        $this->assertNull($cache->get('b'), "'b' should be evicted");
        $this->assertSame(1, $cache->get('a'), "'a' should survive after put promotion");
    }

    public function testSize()
    {
        $cache = new LRUCache(10);
        $this->assertSame(0, $cache->size());

        $cache->set('a', 1);
        $this->assertSame(1, $cache->size());

        $cache->set('b', 2);
        $this->assertSame(2, $cache->size());

        $cache->clear();
        $this->assertSame(0, $cache->size());
    }
}
