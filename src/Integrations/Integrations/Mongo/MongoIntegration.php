<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Integrations\Integration;
use DDTrace\Obfuscation;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;

class MongoIntegration extends Integration
{
    const NAME = 'mongo';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!extension_loaded('mongo') || Versions::phpVersionMatches('5.4')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        /**
         * MongoClient
         */

        \DDTrace\trace_method('MongoClient', '__construct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', '__construct');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_SERVER] = Obfuscation::dsn($args[0]);
                $dbName = MongoIntegration::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->meta[Tag::MONGODB_DATABASE] = Integration::toString($dbName);
                }
            }
            if (isset($args[1]['db'])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[1]['db'];
            }
        });

        \DDTrace\trace_method('MongoClient', 'selectCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[1];
            }
        });

        \DDTrace\trace_method('MongoClient', 'selectDB', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', 'selectDB');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
        });

        \DDTrace\trace_method('MongoClient', 'setReadPreference', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        $this->traceMongoMethod('MongoClient', 'getHosts');
        $this->traceMongoMethod('MongoClient', 'getReadPreference');
        $this->traceMongoMethod('MongoClient', 'getWriteConcern');
        $this->traceMongoMethod('MongoClient', 'listDBs');
        $this->traceMongoMethod('MongoClient', 'setWriteConcern');

        /**
         * MongoCollection
         */

        \DDTrace\trace_method('MongoCollection', '__construct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', '__construct');
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
            function (SpanData $span, $args, $return) use ($integration) {
                $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'createDBRef');
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

        \DDTrace\trace_method('MongoCollection', 'getDBRef', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'getDBRef');

            if (isset($args[0]['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = $args[0]['$id'];
            }
            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0]['$ref'];
            }
        });

        \DDTrace\trace_method('MongoCollection', 'distinct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'distinct');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[1]);
            }
        });

        \DDTrace\trace_method(
            'MongoCollection',
            'setReadPreference',
            function (SpanData $span, $args) use ($integration) {
                $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'setReadPreference');
                if (isset($args[0])) {
                    $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
                }
            }
        );

        $this->traceMongoQuery('MongoCollection', 'count', false);
        $this->traceMongoQuery('MongoCollection', 'find');
        $this->traceMongoQuery('MongoCollection', 'findAndModify');
        $this->traceMongoQuery('MongoCollection', 'findOne');
        $this->traceMongoQuery('MongoCollection', 'remove', false);
        $this->traceMongoQuery('MongoCollection', 'update');

        $this->traceMongoMethod('MongoCollection', 'aggregate');
        $this->traceMongoMethod('MongoCollection', 'aggregateCursor');
        $this->traceMongoMethod('MongoCollection', 'batchInsert');
        $this->traceMongoMethod('MongoCollection', 'createIndex');
        $this->traceMongoMethod('MongoCollection', 'deleteIndex');
        $this->traceMongoMethod('MongoCollection', 'deleteIndexes');
        $this->traceMongoMethod('MongoCollection', 'drop');
        $this->traceMongoMethod('MongoCollection', 'getIndexInfo');
        $this->traceMongoMethod('MongoCollection', 'getName');
        $this->traceMongoMethod('MongoCollection', 'getReadPreference');
        $this->traceMongoMethod('MongoCollection', 'getWriteConcern');
        $this->traceMongoMethod('MongoCollection', 'group');
        $this->traceMongoMethod('MongoCollection', 'insert');
        $this->traceMongoMethod('MongoCollection', 'parallelCollectionScan');
        $this->traceMongoMethod('MongoCollection', 'save');
        $this->traceMongoMethod('MongoCollection', 'setWriteConcern');
        $this->traceMongoMethod('MongoCollection', 'validate');

        /**
         * MongoDB
         */

        \DDTrace\trace_method('MongoDB', 'setReadPreference', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        \DDTrace\trace_method('MongoDB', 'setProfilingLevel', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'setProfilingLevel');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_PROFILING_LEVEL] = json_encode($args[0]);
            }
        });

        \DDTrace\trace_method('MongoDB', 'command', function (SpanData $span, $args, $return) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'command');
            if (isset($args[0]['query'])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]['query']);
            }
            if (isset($args[1]['socketTimeoutMS'])) {
                $span->meta[Tag::MONGODB_TIMEOUT] = $args[1]['socketTimeoutMS'];
            } elseif (isset($args[1]['timeout'])) {
                $span->meta[Tag::MONGODB_TIMEOUT] = $args[1]['timeout'];
            }
        });

        \DDTrace\trace_method('MongoDB', 'createDBRef', function (SpanData $span, $args, $return) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'createDBRef');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
            if (isset($return['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = Integration::toString($return['$id']);
            }
        });

        \DDTrace\trace_method('MongoDB', 'getDBRef', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'getDBRef');
            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]['$ref']);
            }
        });

        \DDTrace\trace_method('MongoDB', 'createCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'createCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
        });

        \DDTrace\trace_method('MongoDB', 'selectCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = Integration::toString($args[0]);
            }
        });

        $this->traceMongoMethod('MongoDB', 'drop');
        $this->traceMongoMethod('MongoDB', 'execute');
        $this->traceMongoMethod('MongoDB', 'getCollectionInfo');
        $this->traceMongoMethod('MongoDB', 'getCollectionNames');
        $this->traceMongoMethod('MongoDB', 'getGridFS');
        $this->traceMongoMethod('MongoDB', 'getProfilingLevel');
        $this->traceMongoMethod('MongoDB', 'getReadPreference');
        $this->traceMongoMethod('MongoDB', 'getWriteConcern');
        $this->traceMongoMethod('MongoDB', 'lastError');
        $this->traceMongoMethod('MongoDB', 'listCollections');
        $this->traceMongoMethod('MongoDB', 'repair');
        $this->traceMongoMethod('MongoDB', 'setWriteConcern');

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
    public function traceMongoMethod($class, $method)
    {
        $integration = $this;
        \DDTrace\trace_method($class, $method, function (SpanData $span) use ($class, $method, $integration) {
            $integration->addSpanDefaultMetadata($span, $class, $method);
        });
    }

    /**
     * Utility method to trace all query methods that have the query as the first argument.
     * If the param {$isTraceAnalithicsCandidate} is set to true (default behavior) the span
     * generated is also marked as trace analytics candidate.
     *
     * @param string $class
     * @param string $method
     * @param boolean $isTraceAnalithicsCandidate [default: `true`]
     */
    public function traceMongoQuery($class, $method, $isTraceAnalithicsCandidate = true)
    {
        $integration = $this;
        \DDTrace\trace_method(
            $class,
            $method,
            function (SpanData $span, $args) use ($class, $method, $isTraceAnalithicsCandidate, $integration) {
                $integration->addSpanDefaultMetadata($span, $class, $method);
                if ($isTraceAnalithicsCandidate) {
                    $integration->addTraceAnalyticsIfEnabled($span);
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
    public function addSpanDefaultMetadata(SpanData $span, $class, $method)
    {
        $span->name = $class . '.' . $method;
        $span->resource = $method;
        $span->type = Type::MONGO;
        $span->service = MongoIntegration::NAME;
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
