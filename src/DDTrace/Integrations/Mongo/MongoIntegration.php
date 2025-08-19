<?php

namespace DDTrace\Integrations\Mongo;

/////////////////////////////////////////////////////////////////////////////////////////////////////////
// DEPRECATED. THIS INTEGRATION IS NO LONGER MAINTAINED.
// PLEASE DO NOT ADD OR BACKPORT FEATURES TO IT.
/////////////////////////////////////////////////////////////////////////////////////////////////////////

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Obfuscation;

class MongoIntegration extends Integration
{
    const NAME = 'mongo';
    const SYSTEM = 'mongodb';

    public static function init(): int
    {
        if (!extension_loaded('mongo')) {
            return Integration::NOT_AVAILABLE;
        }

        /**
         * MongoClient
         */

        \DDTrace\trace_method('MongoClient', '__construct', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoClient', '__construct');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_SERVER] = Obfuscation::dsn($args[0]);
                $dbName = self::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->meta[Tag::MONGODB_DATABASE] = Integration::toString($dbName);
                }
            }
            if (isset($args[1]['db'])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[1]['db'];
            }
        });

        \DDTrace\trace_method('MongoClient', 'selectCollection', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoClient', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[1];
            }
        });

        \DDTrace\trace_method('MongoClient', 'selectDB', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoClient', 'selectDB');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
        });

        \DDTrace\trace_method('MongoClient', 'setReadPreference', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoClient', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        self::traceMongoMethod('MongoClient', 'getHosts');
        self::traceMongoMethod('MongoClient', 'getReadPreference');
        self::traceMongoMethod('MongoClient', 'getWriteConcern');
        self::traceMongoMethod('MongoClient', 'listDBs');
        self::traceMongoMethod('MongoClient', 'setWriteConcern');

        /**
         * MongoCollection
         */

        \DDTrace\trace_method('MongoCollection', '__construct', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoCollection', '__construct');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = Integration::toString($args[0]);
            }
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[1]);
            }
        });

        \DDTrace\trace_method(
            'MongoCollection',
            'createDBRef',
            static function (SpanData $span, $args, $return) {
                self::addSpanDefaultMetadata($span, 'MongoCollection', 'createDBRef');
                if (!is_array($return)) {
                    return;
                }
                if (isset($return['$id'])) {
                    $span->meta[Tag::MONGODB_BSON_ID] = Integration::toString($return['$id']);
                }
                if (isset($return['$ref'])) {
                    $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($return['$ref']);
                }
            }
        );

        \DDTrace\trace_method('MongoCollection', 'getDBRef', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoCollection', 'getDBRef');

            if (isset($args[0]['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = $args[0]['$id'];
            }
            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0]['$ref'];
            }
        });

        \DDTrace\trace_method('MongoCollection', 'distinct', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoCollection', 'distinct');
            self::addTraceAnalyticsIfEnabled($span);
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[1]);
            }
        });

        \DDTrace\trace_method(
            'MongoCollection',
            'setReadPreference',
            static function (SpanData $span, $args) {
                self::addSpanDefaultMetadata($span, 'MongoCollection', 'setReadPreference');
                if (isset($args[0])) {
                    $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
                }
            }
        );

        self::traceMongoQuery('MongoCollection', 'count', false);
        self::traceMongoQuery('MongoCollection', 'find');
        self::traceMongoQuery('MongoCollection', 'findAndModify');
        self::traceMongoQuery('MongoCollection', 'findOne');
        self::traceMongoQuery('MongoCollection', 'remove', false);
        self::traceMongoQuery('MongoCollection', 'update');

        self::traceMongoMethod('MongoCollection', 'aggregate');
        self::traceMongoMethod('MongoCollection', 'aggregateCursor');
        self::traceMongoMethod('MongoCollection', 'batchInsert');
        self::traceMongoMethod('MongoCollection', 'createIndex');
        self::traceMongoMethod('MongoCollection', 'deleteIndex');
        self::traceMongoMethod('MongoCollection', 'deleteIndexes');
        self::traceMongoMethod('MongoCollection', 'drop');
        self::traceMongoMethod('MongoCollection', 'getIndexInfo');
        self::traceMongoMethod('MongoCollection', 'getName');
        self::traceMongoMethod('MongoCollection', 'getReadPreference');
        self::traceMongoMethod('MongoCollection', 'getWriteConcern');
        self::traceMongoMethod('MongoCollection', 'group');
        self::traceMongoMethod('MongoCollection', 'insert');
        self::traceMongoMethod('MongoCollection', 'parallelCollectionScan');
        self::traceMongoMethod('MongoCollection', 'save');
        self::traceMongoMethod('MongoCollection', 'setWriteConcern');
        self::traceMongoMethod('MongoCollection', 'validate');

        /**
         * MongoDB
         */

        \DDTrace\trace_method('MongoDB', 'setReadPreference', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        \DDTrace\trace_method('MongoDB', 'setProfilingLevel', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'setProfilingLevel');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_PROFILING_LEVEL] = json_encode($args[0]);
            }
        });

        \DDTrace\trace_method('MongoDB', 'command', static function (SpanData $span, $args, $return) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'command');
            if (isset($args[0]['query'])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]['query']);
            }
            if (isset($args[1]['socketTimeoutMS'])) {
                $span->meta[Tag::MONGODB_TIMEOUT] = $args[1]['socketTimeoutMS'];
            } elseif (isset($args[1]['timeout'])) {
                $span->meta[Tag::MONGODB_TIMEOUT] = $args[1]['timeout'];
            }
        });

        \DDTrace\trace_method('MongoDB', 'createDBRef', static function (SpanData $span, $args, $return) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'createDBRef');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
            if (isset($return['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = Integration::toString($return['$id']);
            }
        });

        \DDTrace\trace_method('MongoDB', 'getDBRef', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'getDBRef');
            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]['$ref']);
            }
        });

        \DDTrace\trace_method('MongoDB', 'createCollection', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'createCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
        });

        \DDTrace\trace_method('MongoDB', 'selectCollection', static function (SpanData $span, $args) {
            self::addSpanDefaultMetadata($span, 'MongoDB', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
        });

        self::traceMongoMethod('MongoDB', 'drop');
        self::traceMongoMethod('MongoDB', 'execute');
        self::traceMongoMethod('MongoDB', 'getCollectionInfo');
        self::traceMongoMethod('MongoDB', 'getCollectionNames');
        self::traceMongoMethod('MongoDB', 'getGridFS');
        self::traceMongoMethod('MongoDB', 'getProfilingLevel');
        self::traceMongoMethod('MongoDB', 'getReadPreference');
        self::traceMongoMethod('MongoDB', 'getWriteConcern');
        self::traceMongoMethod('MongoDB', 'lastError');
        self::traceMongoMethod('MongoDB', 'listCollections');
        self::traceMongoMethod('MongoDB', 'repair');
        self::traceMongoMethod('MongoDB', 'setWriteConcern');

        return Integration::LOADED;
    }

    /**
     * Trace a generic method mongo method and add only metadata shared by all mongo spans.
     *  - operation name: $class.$method
     *  - resouce       : $method
     *
     * @param string $class
     * @param string $method
     * @param boolean $isTraceAnalithicsCandidate [default: `true`]
     */
    public static function traceMongoMethod($class, $method)
    {
        \DDTrace\trace_method($class, $method, static function (SpanData $span) use ($class, $method) {
            self::addSpanDefaultMetadata($span, $class, $method);
        });
    }

    /**
     * Utility method to trace all query methods that have the query as the first argument.
     * If the param {$isTraceAnalithicsCandidate} is set to true (default behavior) the span
     * generated is also marked as trace analytics candidate.
     *
     * @param string $class
     * @param string $method
     * @param boolean $isTraceAnalyticsCandidate [default: `true`]
     */
    public static function traceMongoQuery($class, $method, $isTraceAnalyticsCandidate = true)
    {
        \DDTrace\trace_method(
            $class,
            $method,
            static function (SpanData $span, $args) use ($class, $method, $isTraceAnalyticsCandidate) {
                self::addSpanDefaultMetadata($span, $class, $method);
                if ($isTraceAnalyticsCandidate) {
                    self::addTraceAnalyticsIfEnabled($span);
                }
                if (isset($args[0])) {
                    $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
                }
            }
        );
    }

    /**
     * Add basic span metadata shared but all spans generated by the mongo integration.
     *
     * @param SpanData $span
     * @param string $class
     * @param string $method
     */
    public static function addSpanDefaultMetadata(SpanData $span, $class, $method)
    {
        $span->name = $class . '.' . $method;
        $span->resource = $method;
        $span->type = Type::MONGO;
        $span->service = self::NAME;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::SYSTEM;
    }

    /**
     * If the `db` option isn't provided via the constructor, we extract
     * the database name from the DSN string if it exists.
     *
     * @param string $dsn
     * @return string|null
     */
    public static function extractDatabaseNameFromDsn($dsn)
    {
        $matches = [];
        if (false === preg_match('/^.+\/\/.+\/(.+)$/', $dsn, $matches)) {
            return null;
        }
        return isset($matches[1]) ? $matches[1] : null;
    }
}
