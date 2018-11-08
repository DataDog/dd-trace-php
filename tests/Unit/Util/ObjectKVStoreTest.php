<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Util\ObjectKVStore;
use PHPUnit\Framework\TestCase;

final class ObjectKVStoreTest extends TestCase
{
    public function test_put_get()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertSame('value', ObjectKVStore::get($instance, 'key'));
    }

    public function test_put_get_different_keys()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key1', 'value1');
        ObjectKVStore::put($instance, 'key2', 'value2');
        $this->assertSame('value1', ObjectKVStore::get($instance, 'key1'));
        $this->assertSame('value2', ObjectKVStore::get($instance, 'key2'));
    }

    public function test_put_get_different_instances_same_key()
    {
        $instance1 = new \stdClass();
        $instance2 = new \stdClass();
        ObjectKVStore::put($instance1, 'key', 'value1');
        ObjectKVStore::put($instance2, 'key', 'value2');
        $this->assertSame('value1', ObjectKVStore::get($instance1, 'key'));
        $this->assertSame('value2', ObjectKVStore::get($instance2, 'key'));
    }

    public function test_put_null_instance_no_exception()
    {
        ObjectKVStore::put(null, 'key', 'value');
        $this->addToAssertionCount(1);
    }

    public function test_put_non_object_instance_no_exception()
    {
        ObjectKVStore::put('instance', 'key', 'value');
        $this->addToAssertionCount(1);
    }

    public function test_put_null_key_no_exception()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, null, 'value');
        $this->addToAssertionCount(1);
    }

    public function test_put_empty_key_no_exception()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, '', 'value');
        $this->addToAssertionCount(1);
    }

    public function test_put_instance_preserves_existing_properties()
    {
        $instance = new \stdClass();
        $instance->existing = 'existing';
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertSame('existing', $instance->existing);
    }

    public function test_get_null_instance()
    {
        $this->assertNull(ObjectKVStore::get(null, 'key'));
    }

    public function test_get_null_instance_default_value()
    {
        $this->assertSame('value', ObjectKVStore::get(null, 'key', 'value'));
    }

    public function test_get_non_object_instance()
    {
        $this->assertNull(ObjectKVStore::get('instance', 'key'));
    }

    public function test_get_non_object_instance_instance_default_value()
    {
        $this->assertSame('value', ObjectKVStore::get('instance', 'key', 'value'));
    }

    public function test_get_null_key()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, null));
    }

    public function test_get_empty_key()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, ''));
    }

    public function test_get_non_string_key()
    {
        $instance = new \stdClass();
        $this->assertNull(ObjectKVStore::get($instance, 1));
    }

    public function test_get_null_key_default_value()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, null, 'value'));
    }

    public function test_get_empty_key_default_value()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, '', 'value'));
    }

    public function test_get_non_string_key_default_value()
    {
        $instance = new \stdClass();
        $this->assertSame('value', ObjectKVStore::get($instance, 1, 'value'));
    }

    public function test_propagate()
    {
        $source = new \stdClass();
        $destination = new \stdClass();
        ObjectKVStore::put($source, 'key', 'value');
        ObjectKVStore::propagate($source, $destination, 'key');
        $this->assertSame('value', ObjectKVStore::get($destination, 'key'));
    }

    public function test_key_is_scoped()
    {
        $instance = new \stdClass();
        ObjectKVStore::put($instance, 'key', 'value');
        $this->assertFalse(property_exists($instance, 'key'));
        $this->assertTrue(property_exists($instance, '__dd_store_key'));
    }
}
