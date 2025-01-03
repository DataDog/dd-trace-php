<?php

namespace DDTrace\Tests\Api\Unit;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Type;
use DDTrace\Format;
use DDTrace\Tag;
use ReflectionClass;

class UserAvailableConstantsTest extends BaseTestCase
{
    /** @dataProvider types */
    public function testTypesAreAccessibleToUserlandCode($value, $expected)
    {
        $this->assertSame($expected, $value);
    }

    public function types()
    {
        return [
            [Type::CACHE, 'cache'],
            [Type::HTTP_CLIENT, 'http'],
            [Type::WEB_SERVLET, 'web'],
            [Type::CLI, 'cli'],
            [Type::SQL, 'sql'],
            [Type::QUEUE, 'queue'],

            [Type::CASSANDRA, 'cassandra'],
            [Type::ELASTICSEARCH, 'elasticsearch'],
            [Type::MEMCACHED, 'memcached'],
            [Type::MONGO, 'mongodb'],
            [Type::OPENAI, 'openai'],
            [Type::REDIS, 'redis'],
            [Type::SYSTEM, 'system'],
        ];
    }

    public function testAllTypesAreTested()
    {
        $definedConstants = \array_values((new ReflectionClass('DDTrace\Type'))->getConstants());
        $tested = \array_map(
            function ($el) {
                return $el[0];
            },
            $this->types()
        );

        $this->assertSame($definedConstants, $tested);
    }

    /** @dataProvider formats */
    public function testFormatsAreAccessibleToUserlandCode($value, $expected)
    {
        $this->assertSame($expected, $value);
    }

    public function formats()
    {
        return [
            [Format::BINARY, 'binary'],
            [Format::TEXT_MAP, 'text_map'],
            [Format::HTTP_HEADERS, 'http_headers'],
        ];
    }

    /** @dataProvider tags */
    public function testTagsAreAccessibleToUserlandCode($value, $expected)
    {
        $this->assertSame($expected, $value);
    }

    public function testAllFormatsAreTested()
    {
        $definedConstants = \array_values((new ReflectionClass('DDTrace\Format'))->getConstants());
        $tested = \array_map(
            function ($el) {
                return $el[0];
            },
            $this->formats()
        );

        $this->assertSame($definedConstants, $tested);
    }

    public function tags()
    {
        return [
            [Tag::ENV, 'env'],
            [Tag::SPAN_TYPE, 'span.type'],
            [Tag::SPAN_KIND, 'span.kind'],
            [Tag::SPAN_KIND_VALUE_SERVER, 'server'],
            [Tag::SPAN_KIND_VALUE_CLIENT, 'client'],
            [Tag::SPAN_KIND_VALUE_PRODUCER, 'producer'],
            [Tag::SPAN_KIND_VALUE_CONSUMER, 'consumer'],
            [Tag::SPAN_KIND_VALUE_INTERNAL, 'internal'],
            [Tag::COMPONENT, 'component'],
            [Tag::SERVICE_NAME, 'service.name'],
            [Tag::MANUAL_KEEP, 'manual.keep'],
            [Tag::MANUAL_DROP, 'manual.drop'],
            [Tag::PID, 'process_id'],
            [Tag::RESOURCE_NAME, 'resource.name'],
            [Tag::DB_STATEMENT, 'sql.query'],
            [Tag::ERROR, 'error'],
            [Tag::ERROR_MSG, 'error.message'],
            [Tag::ERROR_TYPE, 'error.type'],
            [Tag::ERROR_STACK, 'error.stack'],
            [Tag::HTTP_METHOD, 'http.method'],
            [Tag::HTTP_ROUTE, 'http.route'],
            [Tag::HTTP_STATUS_CODE, 'http.status_code'],
            [Tag::HTTP_URL, 'http.url'],
            [Tag::HTTP_VERSION, 'http.version'],
            [Tag::LOG_EVENT, 'event'],
            [Tag::LOG_ERROR, 'error'],
            [Tag::LOG_ERROR_OBJECT, 'error.object'],
            [Tag::LOG_MESSAGE, 'message'],
            [Tag::LOG_STACK, 'stack'],
            [Tag::NETWORK_DESTINATION_NAME, 'network.destination.name'],
            [Tag::TARGET_HOST, 'out.host'],
            [Tag::TARGET_PORT, 'out.port'],
            [Tag::BYTES_OUT, 'net.out.bytes'],
            [Tag::ANALYTICS_KEY, '_dd1.sr.eausr'],
            [Tag::HOSTNAME, '_dd.hostname'],
            [Tag::ORIGIN, '_dd.origin'],
            [Tag::VERSION, 'version'],
            [Tag::SERVICE_VERSION, 'service.version'],
            [Tag::ELASTICSEARCH_BODY, 'elasticsearch.body'],
            [Tag::ELASTICSEARCH_METHOD, 'elasticsearch.method'],
            [Tag::ELASTICSEARCH_PARAMS, 'elasticsearch.params'],
            [Tag::ELASTICSEARCH_URL, 'elasticsearch.url'],
            [Tag::DB_NAME, 'db.name'],
            [Tag::DB_CHARSET, 'db.charset'],
            [Tag::DB_INSTANCE, 'db.instance'],
            [Tag::DB_TYPE, 'db.type'],
            [Tag::DB_SYSTEM, 'db.system'],
            [Tag::DB_ROW_COUNT, 'db.row_count'],
            [Tag::DB_STMT, 'db.statement'],
            [Tag::DB_USER, 'db.user'],
            [Tag::KAFKA_CLIENT_ID, 'messaging.kafka.client_id'],
            [Tag::KAFKA_GROUP_ID, 'messaging.kafka.group_id'],
            [Tag::KAFKA_HOST_LIST, 'messaging.kafka.bootstrap.servers'],
            [Tag::KAFKA_MESSAGE_KEY, 'messaging.kafka.message_key'],
            [Tag::KAFKA_MESSAGE_OFFSET, 'messaging.kafka.message_offset'],
            [Tag::KAFKA_PARTITION, 'messaging.kafka.partition'],
            [Tag::KAFKA_TOMBSTONE, 'messaging.kafka.tombstone'],
            [Tag::KAFKA_PRODUCE, 'kafka.produce'],
            [Tag::KAFKA_CONSUME, 'kafka.consume'],
            [Tag::LARAVELQ_ATTEMPTS, 'messaging.laravel.attempts'],
            [Tag::LARAVELQ_BATCH_ID, 'messaging.laravel.batch_id'],
            [Tag::LARAVELQ_CONNECTION, 'messaging.laravel.connection'],
            [Tag::LARAVELQ_MAX_TRIES, 'messaging.laravel.max_tries'],
            [Tag::LARAVELQ_NAME, 'messaging.laravel.name'],
            [Tag::LARAVELQ_TIMEOUT, 'messaging.laravel.timeout'],
            [Tag::MONGODB_BSON_ID, 'mongodb.bson.id'],
            [Tag::MONGODB_COLLECTION, 'mongodb.collection'],
            [Tag::MONGODB_DATABASE, 'mongodb.db'],
            [Tag::MONGODB_PROFILING_LEVEL, 'mongodb.profiling_level'],
            [Tag::MONGODB_READ_PREFERENCE, 'mongodb.read_preference'],
            [Tag::MONGODB_SERVER, 'mongodb.server'],
            [Tag::MONGODB_TIMEOUT, 'mongodb.timeout'],
            [Tag::MONGODB_QUERY, 'mongodb.query'],
            [Tag::REDIS_RAW_COMMAND, 'redis.raw_command'],
            [Tag::MQ_SYSTEM, 'messaging.system'],
            [Tag::MQ_DESTINATION, 'messaging.destination'],
            [Tag::MQ_DESTINATION_KIND, 'messaging.destination_kind'],
            [Tag::MQ_TEMP_DESTINATION, 'messaging.temp_destination'],
            [Tag::MQ_PROTOCOL, 'messaging.protocol'],
            [Tag::MQ_PROTOCOL_VERSION, 'messaging.protocol_version'],
            [Tag::MQ_URL, 'messaging.url'],
            [Tag::MQ_MESSAGE_ID, 'messaging.message_id'],
            [Tag::MQ_CONVERSATION_ID, 'messaging.conversation_id'],
            [Tag::MQ_MESSAGE_PAYLOAD_SIZE, 'messaging.message_payload_size_bytes'],
            [Tag::MQ_OPERATION, 'messaging.operation'],
            [Tag::MQ_CONSUMER_ID, 'messaging.consumer_id'],
            [Tag::MQ_OPERATION_PROCESS, 'process'],
            [Tag::MQ_OPERATION_RECEIVE, 'receive'],
            [Tag::MQ_OPERATION_SEND, 'send'],
            [Tag::RABBITMQ_DELIVERY_MODE, 'messaging.rabbitmq.delivery_mode'],
            [Tag::RABBITMQ_EXCHANGE, 'messaging.rabbitmq.exchange'],
            [Tag::RABBITMQ_ROUTING_KEY, 'messaging.rabbitmq.routing_key'],
            [Tag::EXEC_CMDLINE_EXEC, 'cmd.exec'],
            [Tag::EXEC_CMDLINE_SHELL, 'cmd.shell'],
            [Tag::EXEC_TRUNCATED, 'cmd.truncated'],
            [Tag::EXEC_EXIT_CODE, 'cmd.exit_code'],
        ];
    }

    public function testAllTagsAreTested()
    {
        $definedConstants = \array_values((new ReflectionClass('DDTrace\Tag'))->getConstants());
        $tested = \array_map(
            function ($el) {
                return $el[0];
            },
            $this->tags()
        );

        $this->assertSame($definedConstants, $tested);
    }
}
