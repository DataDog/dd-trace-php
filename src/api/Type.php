<?php

namespace DDTrace;

class Type
{
    const CACHE = 'cache';
    const HTTP_CLIENT = 'http';
    const WEB_SERVLET = 'web';
    const CLI = 'cli';
    const SQL = 'sql';

    const MESSAGE_CONSUMER = 'queue';
    const MESSAGE_PRODUCER = 'queue';

    const LLM = 'llm';

    const CASSANDRA = 'cassandra';
    const ELASTICSEARCH = 'elasticsearch';
    const MEMCACHED = 'memcached';
    const MONGO = 'mongodb';
    const OPENAI = 'openai';
    const REDIS = 'redis';

    const SYSTEM = 'system';
}
