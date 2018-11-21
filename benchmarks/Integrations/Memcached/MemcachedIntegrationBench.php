<?php

use DDTrace\DebugTracer;
use DDTrace\Integrations\Memcached\MemcachedIntegration;

/**
 * @BeforeMethods({"initConnection", "initTracer"})
 * @AfterMethods({"flushTracer"})
 */
class MemcachedIntegrationBench
{
    use DebugTracer;

    const HOST = 'memcached_integration';
    const PORT = '11211';

    /** @var \Memcached */
    private $memcached;

    public function initConnection()
    {
        $this->memcached = new \Memcached;
        $this->memcached->addServer(self::HOST, self::PORT);
        $this->memcached->flush();
    }

    public function benchBaseline()
    {
        $this->doCacheStuff();
    }

    public function benchWithTracing()
    {
        MemcachedIntegration::load();
        $this->doCacheStuff();
    }

    private function doCacheStuff()
    {
        $this->memcached->get('foo');
        $this->memcached->set('foo', 'bar');
        $this->memcached->get('foo');
        $this->memcached->increment('count', 2);
        $this->memcached->get('count');
    }
}
