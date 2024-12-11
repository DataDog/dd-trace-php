<?php

namespace DDTrace;

class Tag
{
    // Generic
    const ENV = 'env';
    const SPAN_TYPE = 'span.type';
    const SPAN_KIND = 'span.kind';
    const SPAN_KIND_VALUE_SERVER = 'server';
    const SPAN_KIND_VALUE_CLIENT = 'client';
    const SPAN_KIND_VALUE_PRODUCER = 'producer';
    const SPAN_KIND_VALUE_CONSUMER = 'consumer';
    const SPAN_KIND_VALUE_INTERNAL = 'internal';
    const COMPONENT = 'component';
    const SERVICE_NAME = 'service.name';
    const MANUAL_KEEP = 'manual.keep';
    const MANUAL_DROP = 'manual.drop';
    const PID = 'process_id';
    const RESOURCE_NAME = 'resource.name';
    const DB_STATEMENT = 'sql.query';
    const ERROR = 'error';
    const ERROR_MSG = 'error.message'; // string representing the error message
    const ERROR_TYPE = 'error.type'; // string representing the type of the error
    const ERROR_STACK = 'error.stack'; // human readable version of the stack
    const HTTP_METHOD = 'http.method';
    const HTTP_ROUTE = 'http.route';
    const HTTP_STATUS_CODE = 'http.status_code';
    const HTTP_URL = 'http.url';
    const HTTP_VERSION = 'http.version';
    const LOG_EVENT = 'event';
    const LOG_ERROR = 'error';
    const LOG_ERROR_OBJECT = 'error.object';
    const LOG_MESSAGE = 'message';
    const LOG_STACK = 'stack';
    const NETWORK_DESTINATION_NAME = 'network.destination.name';
    const TARGET_HOST = 'out.host';
    const TARGET_PORT = 'out.port';
    const BYTES_OUT = 'net.out.bytes';
    const ANALYTICS_KEY = '_dd1.sr.eausr';
    const HOSTNAME = '_dd.hostname';
    const ORIGIN = '_dd.origin';
    const VERSION = 'version';
    const SERVICE_VERSION = 'service.version'; // OpenTelemetry compatible tag

    // Elasticsearch
    const ELASTICSEARCH_BODY = 'elasticsearch.body';
    const ELASTICSEARCH_METHOD = 'elasticsearch.method';
    const ELASTICSEARCH_PARAMS = 'elasticsearch.params';
    const ELASTICSEARCH_URL = 'elasticsearch.url';

    // Database
    const DB_NAME = 'db.name';
    const DB_CHARSET = 'db.charset';
    const DB_INSTANCE = 'db.instance';
    const DB_TYPE = 'db.type';
    const DB_SYSTEM = 'db.system';
    const DB_ROW_COUNT = 'db.row_count';
    const DB_STMT = 'db.statement';
    const DB_USER = 'db.user';

    // Laravel Queue
    const LARAVELQ_ATTEMPTS = 'messaging.laravel.attempts';
    const LARAVELQ_BATCH_ID = 'messaging.laravel.batch_id';
    const LARAVELQ_CONNECTION = 'messaging.laravel.connection';
    const LARAVELQ_MAX_TRIES = 'messaging.laravel.max_tries';
    const LARAVELQ_NAME = 'messaging.laravel.name';
    const LARAVELQ_TIMEOUT = 'messaging.laravel.timeout';

    // MongoDB
    const MONGODB_BSON_ID = 'mongodb.bson.id';
    const MONGODB_COLLECTION = 'mongodb.collection';
    const MONGODB_DATABASE = 'mongodb.db';
    const MONGODB_PROFILING_LEVEL = 'mongodb.profiling_level';
    const MONGODB_READ_PREFERENCE = 'mongodb.read_preference';
    const MONGODB_SERVER = 'mongodb.server';
    const MONGODB_TIMEOUT = 'mongodb.timeout';
    const MONGODB_QUERY = 'mongodb.query';

    // REDIS
    const REDIS_RAW_COMMAND = 'redis.raw_command';

    // Message Queue
    const MQ_SYSTEM = 'messaging.system';
    const MQ_DESTINATION = 'messaging.destination';
    const MQ_DESTINATION_KIND = 'messaging.destination_kind';
    const MQ_TEMP_DESTINATION = 'messaging.temp_destination';
    const MQ_PROTOCOL = 'messaging.protocol';
    const MQ_PROTOCOL_VERSION = 'messaging.protocol_version';
    const MQ_URL = 'messaging.url';
    const MQ_MESSAGE_ID = 'messaging.message_id';
    const MQ_CONVERSATION_ID = 'messaging.conversation_id';
    const MQ_MESSAGE_PAYLOAD_SIZE = 'messaging.message_payload_size_bytes';
    const MQ_OPERATION = 'messaging.operation';
    const MQ_CONSUMER_ID = 'messaging.consumer_id';

    // RabbitMQ
    const RABBITMQ_DELIVERY_MODE = 'messaging.rabbitmq.delivery_mode';
    const RABBITMQ_EXCHANGE = 'messaging.rabbitmq.exchange';
    const RABBITMQ_ROUTING_KEY = 'messaging.rabbitmq.routing_key';

    // Exec
    const EXEC_CMDLINE_EXEC = 'cmd.exec';
    const EXEC_CMDLINE_SHELL = 'cmd.shell';
    const EXEC_TRUNCATED = 'cmd.truncated';
    const EXEC_EXIT_CODE = 'cmd.exit_code';
}
