<?php

namespace DDTrace;

class Type
{
    const CACHE = 'cache';
    const HTTP_CLIENT = 'http';
    const WEB_SERVLET = 'web';
    const SQL = 'sql';

    const MESSAGE_CONSUMER = 'queue';
    const MESSAGE_PRODUCER = 'queue';

    const CASSANDRA = 'cassandra';
    const ELASTICSEARCH = 'elasticsearch';
    const MEMCACHED = 'memcached';
    const MONGO = 'mongodb';
    const REDIS = 'redis';
}
