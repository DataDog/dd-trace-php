<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Obfuscation;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;

class MongoSandboxedIntegration extends SandboxedIntegration
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
            return SandboxedIntegration::NOT_AVAILABLE;
        }

        $integration = $this;

        /**
         * MongoClient
         */

        dd_trace_method('MongoClient', '__construct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', '__construct');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_SERVER] = Obfuscation::dsn($args[0]);
                $dbName = MongoSandboxedIntegration::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->meta[Tag::MONGODB_DATABASE] = $dbName;
                }
            }
            if (isset($args[1]['db'])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[1]['db'];
            }
        });

        dd_trace_method('MongoClient', 'selectCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[1];
            }
        });

        dd_trace_method('MongoClient', 'selectDB', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoClient', 'selectDB');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
        });

        dd_trace_method('MongoClient', 'setReadPreference', function (SpanData $span, $args) use ($integration) {
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

        dd_trace_method('MongoCollection', '__construct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', '__construct');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_DATABASE] = $args[0];
            }
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[1];
            }
        });

        dd_trace_method('MongoCollection', 'count', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'count');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoCollection', 'createDBRef', function (SpanData $span, $args, $return) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'createDBRef');
            if (!is_array($return)) {
                return;
            }
            if (isset($return['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = $return['$id'];
            }
            if (isset($return['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $return['$ref'];
            }
        });

        dd_trace_method('MongoCollection', 'getDBRef', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'getDBRef');

            if (isset($args[0]['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = $args[0]['$id'];
            }
            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0]['$ref'];
            }
        });

        dd_trace_method('MongoCollection', 'distinct', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'distinct');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[1])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[1]);
            }
        });

        dd_trace_method('MongoCollection', 'find', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'find');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoCollection', 'findAndModify', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'findAndModify');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoCollection', 'findOne', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'findOne');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoCollection', 'remove', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'remove');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoCollection', 'setReadPreference', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        dd_trace_method('MongoCollection', 'update', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoCollection', 'update');
            $integration->addTraceAnalyticsIfEnabled($span);
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_QUERY] = json_encode($args[0]);
            }
        });

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

        dd_trace_method('MongoDB', 'setReadPreference', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'setReadPreference');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_READ_PREFERENCE] = $args[0];
            }
        });

        dd_trace_method('MongoDB', 'setProfilingLevel', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'setProfilingLevel');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_PROFILING_LEVEL] = json_encode($args[0]);
            }
        });

        dd_trace_method('MongoDB', 'command', function (SpanData $span, $args, $return) use ($integration) {
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

        dd_trace_method('MongoDB', 'createDBRef', function (SpanData $span, $args, $return) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'createDBRef');

            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0];
            }

            if (isset($return['$id'])) {
                $span->meta[Tag::MONGODB_BSON_ID] = $return['$id'];
            }
        });

        dd_trace_method('MongoDB', 'getDBRef', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'getDBRef');

            if (isset($args[0]['$ref'])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0]['$ref'];
            }
        });

        dd_trace_method('MongoDB', 'createCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'createCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0];
            }
        });

        dd_trace_method('MongoDB', 'selectCollection', function (SpanData $span, $args) use ($integration) {
            $integration->addSpanDefaultMetadata($span, 'MongoDB', 'selectCollection');
            if (isset($args[0])) {
                $span->meta[Tag::MONGODB_COLLECTION] = $args[0];
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

        return SandboxedIntegration::LOADED;
    }


    public function traceMongoMethod($class, $method)
    {
        $integration = $this;
        dd_trace_method($class, $method, function (SpanData $span) use ($class, $method, $integration) {
            $integration->addSpanDefaultMetadata($span, $class, $method);
        });
    }

    public function addSpanDefaultMetadata(SpanData $span, $class, $method)
    {
        $span->name = $class . '.' . $method;
        $span->resource = $method;
        $span->type = Type::MONGO;
        $span->service = MongoSandboxedIntegration::NAME;
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
