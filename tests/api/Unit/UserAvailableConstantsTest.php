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

            [Type::MESSAGE_CONSUMER, 'queue'],
            [Type::MESSAGE_PRODUCER, 'queue'],

            [Type::CASSANDRA, 'cassandra'],
            [Type::ELASTICSEARCH, 'elasticsearch'],
            [Type::MEMCACHED, 'memcached'],
            [Type::MONGO, 'mongodb'],
            [Type::REDIS, 'redis'],
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
            [Format::CURL_HTTP_HEADERS, 'curl_http_headers'],
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
            [Tag::SERVICE_NAME, 'service.name'],
            [Tag::MANUAL_KEEP, 'manual.keep'],
            [Tag::MANUAL_DROP, 'manual.drop'],
            [Tag::PID, 'process_id'],
            [Tag::RESOURCE_NAME, 'resource.name'],
            [Tag::DB_STATEMENT, 'sql.query'],
            [Tag::ERROR, 'error'],
            [Tag::ERROR_MSG, 'error.message'],
            [Tag::ERROR_MSG, 'error.msg'],
            [Tag::ERROR_TYPE, 'error.type'],
            [Tag::ERROR_STACK, 'error.stack'],
            [Tag::HTTP_METHOD, 'http.method'],
            [Tag::HTTP_STATUS_CODE, 'http.status_code'],
            [Tag::HTTP_URL, 'http.url'],
            [Tag::LOG_EVENT, 'event'],
            [Tag::LOG_ERROR, 'error'],
            [Tag::LOG_ERROR_OBJECT, 'error.object'],
            [Tag::LOG_MESSAGE, 'message'],
            [Tag::LOG_STACK, 'stack'],
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
            [Tag::MONGODB_BSON_ID, 'mongodb.bson.id'],
            [Tag::MONGODB_COLLECTION, 'mongodb.collection'],
            [Tag::MONGODB_DATABASE, 'mongodb.db'],
            [Tag::MONGODB_PROFILING_LEVEL, 'mongodb.profiling_level'],
            [Tag::MONGODB_READ_PREFERENCE, 'mongodb.read_preference'],
            [Tag::MONGODB_SERVER, 'mongodb.server'],
            [Tag::MONGODB_TIMEOUT, 'mongodb.timeout'],
            [Tag::MONGODB_QUERY, 'mongodb.query'],
            [Tag::REDIS_RAW_COMMAND, 'redis.raw_command'],
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
