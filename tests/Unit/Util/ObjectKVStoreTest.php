<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Util\ObjectKVStore;

final class ObjectKVStoreTest extends BaseTestCase
{
    public function testPutGet()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertSame('value', ObjectKVStore::get($instance, 'key'));
    }

    public function testPutGetDifferentKeys()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key1', 'value1');
        ObjectKVStore::put($instance, 'key2', 'value2');
        $this->assertSame('value1', ObjectKVStore::get($instance, 'key1'));
        $this->assertSame('value2', ObjectKVStore::get($instance, 'key2'));
    }

    public function testPutGetDifferentInstancesSameKey()
    {
        $instance1 = new \stdClass();
        $instance2 = new \stdClass();
        ObjectKVStore::put($instance1, 'key', 'value1');
        ObjectKVStore::put($instance2, 'key', 'value2');
        $this->assertSame('value1', ObjectKVStore::get($instance1, 'key'));
        $this->assertSame('value2', ObjectKVStore::get($instance2, 'key'));
    }

    public function testPutNullInstanceNoException()
    {
        ObjectKVStore::put(null, 'key', 'value');
        $this->addToAssertionCount(1);
    }

    public function testPutNonObjectInstanceNoException()
    {
        ObjectKVStore::put('instance', 'key', 'value');
        $this->addToAssertionCount(1);
    }

    public function testPutNullKeyNoException()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, null, 'value');
        $this->addToAssertionCount(1);
    }

    public function testPutEmptyKeyNoException()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, '', 'value');
        $this->addToAssertionCount(1);
    }

    public function testPutInstancePreservesExistingProperties()
    {
        $instance = new \stdClass();
        $instance->existing = 'existing';
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertSame('existing', $instance->existing);
    }

    public function testGetNullInstance()
    {
        $this->assertNull(ObjectKVStore::get(null, 'key'));
    }

    public function testGetNullInstanceDefaultValue()
    {
        $this->assertSame('value', ObjectKVStore::get(null, 'key', 'value'));
    }

    public function testGetNonObjectInstance()
    {
        $this->assertNull(ObjectKVStore::get('instance', 'key'));
    }

    public function testGetNonObjectInstanceInstanceDefaultValue()
    {
        $this->assertSame('value', ObjectKVStore::get('instance', 'key', 'value'));
    }

    public function testGetNullKey()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, null));
    }

    public function testGetEmptyKey()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, ''));
    }

    public function testGetNonStringKey()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, 1));
    }

    public function testGetNullKeyDefaultValue()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, null, 'value'));
    }

    public function testGetEmptyKeyDefaultValue()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, '', 'value'));
    }

    public function testGetNonStringKeyDefaultValue()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, 1, 'value'));
    }

    public function testPropagate()
    {
        $source = new \stdClass();
        $destination = new \stdClass();
        ObjectKVStore::put($source, 'key', 'value');
        ObjectKVStore::propagate($source, $destination, 'key');
        $this->assertSame('value', ObjectKVStore::get($destination, 'key'));
    }

    public function testKeyIsScoped()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertFalse(property_exists($instance, 'key'));
        $this->assertTrue(property_exists($instance, '__dd_store_key'));
    }
}
