<?php

namespace DDTrace;

class Type
{
    const CACHE = 'cache';
    const HTTP_CLIENT = 'http';
    const WEB_SERVLET = 'web';
    const CLI = 'cli';
    const SQL = 'sql';
    const QUEUE = 'queue';
    const WEBSOCKET = 'websocket';

    /**
     * @deprecated use QUEUE instead
     */
    const MESSAGE_CONSUMER = 'queue';
    /**
     * @deprecated use QUEUE instead
     */
    const MESSAGE_PRODUCER = 'queue';

    const CASSANDRA = 'cassandra';
    const ELASTICSEARCH = 'elasticsearch';
    const MEMCACHED = 'memcached';
    const MONGO = 'mongodb';
    const OPENAI = 'openai';
    const REDIS = 'redis';

    const SYSTEM = 'system';

    const GRAPHQL = 'graphql';
}
