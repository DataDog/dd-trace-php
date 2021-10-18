<?php

namespace DDTrace\Integrations\MongoDB;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

/**
 * Defines and register a subscriber. It is done in a function, rather than at a root of any PHP file, so the interface
 * \MongoDB\Driver\Monitoring\CommandSubscriber is only required to exist if this integration is loaded.
 */
function register_subscriber()
{
    class DatadogSubscriber implements \MongoDB\Driver\Monitoring\CommandSubscriber
    {
        public function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event)
        {
            $span = \DDTrace\active_span();
            if ($span) {
                $span->meta['out.host'] = $event->getServer()->getHost();
                $span->meta['out.port'] = $event->getServer()->getPort();
            }
        }

        public function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent $event)
        {
        }

        public function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent $event)
        {
        }
    }

    \MongoDB\Driver\Monitoring\addSubscriber(new DatadogSubscriber());
}

class MongoDBIntegration extends Integration
{
    const NAME = 'mongodb';

    private static $loaded = false;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        // We have multiple methods that cause this integration to be loaded.
        // Integration loading should be cached, for now we keep track of the initialization execution.
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!extension_loaded('mongodb')) {
            return Integration::NOT_AVAILABLE;
        }

        register_subscriber();

        // see: https://docs.mongodb.com/manual/reference/command/
        $knownCommands = [
            '_isSelf',
            'abortReshardCollection',
            'abortTransaction',
            'addShard',
            'addShardToZone',
            'aggregate',
            'applyOps',
            'authenticate',
            'availableQueryOptions',
            'balancerCollectionStatus',
            'balancerStart',
            'balancerStatus',
            'balancerStop',
            'buildInfo',
            'checkShardingIndex',
            'cleanupOrphaned',
            'cleanupReshardCollection',
            'clearJumboFlag',
            'cloneCollectionAsCapped',
            'collMod',
            'collStats',
            'commitReshardCollection',
            'commitTransaction',
            'compact',
            'connectionStatus',
            'connPoolStats',
            'connPoolSync',
            'convertToCapped',
            'count',
            'create',
            'createIndexes',
            'createRole',
            'createUser',
            'currentOp',
            'cursorInfo',
            'dataSize',
            'dbHash',
            'dbStats',
            'delete',
            'distinct',
            'driverOIDTest',
            'drop',
            'dropAllRolesFromDatabase',
            'dropAllUsersFromDatabase',
            'dropConnections',
            'dropDatabase',
            'dropIndexes',
            'dropRole',
            'dropUser',
            'enableSharding',
            'endSessions',
            'explain',
            'features',
            'filemdfsync',
            'find',
            'findAndModify',
            'flushRouterConfig',
            'fsyncUnlock',
            'geoSearch',
            'getCmdLineOpts',
            'getDefaultRWConcern',
            'getLastError',
            'getLog',
            'getMore',
            'getnonce',
            'getParameter',
            'getShardMap',
            'getShardVersion',
            'grantPrivilegesToRole',
            'grantRolesToRole',
            'grantRolesToUser',
            'hello',
            'hostInfo',
            'insert',
            'invalidateUserCache',
            'isdbgrid',
            'killAllSessions',
            'killAllSessionsByPattern',
            'killCursors',
            'killOp',
            'killSessions',
            'listCollections',
            'listCommands',
            'listDatabases',
            'listIndexes',
            'listShards',
            'lockInfo',
            'logApplicationMessage',
            'logout',
            'logRotate',
            'mapReduce',
            'medianKey',
            'mergeChunks',
            'moveChunk',
            'movePrimary',
            'netstat',
            'ping',
            'planCacheClear',
            'planCacheClearFilters',
            'planCacheListFilters',
            'planCacheSetFilter',
            'profile',
            'refineCollectionShardKey',
            'refreshSessions',
            'reIndex',
            'removeShard',
            'removeShardFromZone',
            'renameCollection',
            'replSetAbortPrimaryCatchUp',
            'replSetFreeze',
            'replSetGetConfig',
            'replSetGetStatus',
            'replSetInitiate',
            'replSetMaintenance',
            'replSetReconfig',
            'replSetResizeOplog',
            'replSetStepDown',
            'replSetSyncFrom',
            'resetError',
            'reshardCollection',
            'revokePrivilegesFromRole',
            'revokeRolesFromRole',
            'revokeRolesFromUser',
            'rolesInfo',
            'rotateCertificates',
            'serverStatus',
            'setDefaultRWConcern',
            'setFeatureCompatibilityVersion',
            'setFreeMonitoring',
            'setIndexCommitQuorum',
            'setParameter',
            'setShardVersion',
            'shardCollection',
            'shardConnPoolStats',
            'shardingState',
            'shutdown',
            'split',
            'splitChunk',
            'splitVector',
            'startSession',
            'top',
            'unsetSharding',
            'update',
            'updateRole',
            'updateUser',
            'updateZoneKeyRange',
            'usersInfo',
            'validate',
            'whatsmyuri',
        ];

        $this->traceExecuteQuery('MongoDB\Driver\Manager', 'executeQuery');
        $this->traceExecuteQuery('MongoDB\Driver\Server', 'executeQuery');

        $this->traceExecuteBulkWrite('MongoDB\Driver\Manager', 'executeBulkWrite');
        $this->traceExecuteBulkWrite('MongoDB\Driver\Server', 'executeBulkWrite');

        $this->traceExecuteCommand('MongoDB\Driver\Manager', 'executeCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Server', 'executeCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Manager', 'executeWriteCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Server', 'executeWriteCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Manager', 'executeReadCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Server', 'executeReadCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Manager', 'executeReadWriteCommand', $knownCommands);
        $this->traceExecuteCommand('MongoDB\Driver\Server', 'executeReadWriteCommand', $knownCommands);

        // See: https://docs.mongodb.com/php-library/current/reference/class/MongoDBCollection/
        $collectionMethodsWithFilter = [
            'aggregate',
            'count',
            'countDocuments',
            'deleteMany',
            'deleteOne',
            'find',
            'findOne',
            'findOneAndDelete',
            'findOneAndReplace',
            'findOneAndUpdate',
            'replaceOne',
            'updateMany',
            'updateOne',
        ];

        foreach ($collectionMethodsWithFilter as $method) {
            $this->traceCollectionMethodWithFilter($method);
        }

        $collectionMethodsNoFilter = [
            'bulkWrite',
            'drop',
            'dropIndexes',
            'estimatedDocumentCount',
            'insertMany',
            'insertOne',
            'listIndexes',
            'mapReduce',
        ];

        foreach ($collectionMethodsNoFilter as $method) {
            $this->traceCollectionMethodNoArgs($method);
        }

        \DDTrace\hook_method(
            'MongoDB\Driver\Query',
            '__construct',
            null,
            function ($self, $_2, $args, $_4) {
                if (isset($args[0])) {
                    ObjectKVStore::put($self, 'filter', $args[0]);
                }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\Command',
            '__construct',
            null,
            function ($self, $_2, $args, $_4) {
                if (isset($args[0])) {
                    ObjectKVStore::put($self, 'cmd', $args[0]);
                }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\BulkWrite',
            'delete',
            null,
            function ($self, $_2, $args, $_4) {
                if (isset($args[0])) {
                    $existingDeletes = ObjectKVStore::get($self, 'deletes', []);
                    \array_push($existingDeletes, MongoDBIntegration::serializeQuery($args[0]));
                    ObjectKVStore::put($self, 'deletes', $existingDeletes);
                }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\BulkWrite',
            'update',
            null,
            function ($self, $_2, $args, $_4) {
                if (isset($args[0])) {
                    $existingUpdates = ObjectKVStore::get($self, 'updates', []);
                    \array_push($existingUpdates, MongoDBIntegration::serializeQuery($args[0]));
                    ObjectKVStore::put($self, 'updates', $existingUpdates);
                }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\BulkWrite',
            'insert',
            null,
            function ($self, $_2, $args, $_4) {
                $existingInsertCount = ObjectKVStore::get($self, 'insertsCount', 0);
                ObjectKVStore::put($self, 'insertsCount', $existingInsertCount + 1);
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\Manager',
            'selectServer',
            null,
            function ($self, $_2, $_3, $server) {
                ObjectKVStore::put($self, 'host', $server->getHost());
                ObjectKVStore::put($self, 'port', $server->getPort());
            }
        );

        return Integration::LOADED;
    }

    /**
     * Traces a collection method which is supposed to have a filter as the first argument. It is resilient to calls
     * without an argument, in that case no query is attached.
     *
     * @param string $method
     * @return void
     */
    public function traceCollectionMethodWithFilter($method)
    {
        $integration = $this;
        \DDTrace\trace_method(
            'MongoDB\Collection',
            $method,
            function (SpanData $span, $args) use ($method, $integration) {
                $integration->setMetadata(
                    $span,
                    'mongodb.cmd',
                    $method,
                    $this->getDatabaseName(),
                    $this->getCollectionName(),
                    ObjectKVStore::get($this->getManager(), 'host'),
                    ObjectKVStore::get($this->getManager(), 'port'),
                    null,
                    empty($args[0]) ? null : $args[0]
                );
            }
        );
    }

    /**
     * Traces a collection method without storing any information about its arguments.
     *
     * @param string $method
     * @return void
     */
    public function traceCollectionMethodNoArgs($method)
    {
        $integration = $this;
        \DDTrace\trace_method(
            'MongoDB\Collection',
            $method,
            function (SpanData $span, $args) use ($method, $integration) {
                $integration->setMetadata(
                    $span,
                    'mongodb.cmd',
                    $method,
                    $this->getDatabaseName(),
                    $this->getCollectionName(),
                    ObjectKVStore::get($this->getManager(), 'host'),
                    ObjectKVStore::get($this->getManager(), 'port'),
                    null,
                    null
                );
            }
        );
    }

    /**
     * Traces Manager/Server::executeQuery
     *
     * @param string $class
     * @param string $method
     * @return void
     */
    public function traceExecuteQuery($class, $method)
    {
        $integration = $this;
        \DDTrace\trace_method($class, $method, function ($span, $args) use ($integration) {
            list($database, $collection) = MongoDBIntegration::parseNamespace(isset($args[0]) ? $args[0] : null);

            $integration->setMetadata(
                $span,
                'mongodb.driver.cmd',
                'executeQuery',
                $database,
                $collection,
                null,
                null,
                null,
                ObjectKVStore::get($args[1], 'filter', null)
            );
        });
    }

    /**
     * Traces Manager/Server::executeBulkWrite
     *
     * @param string $class
     * @param string $method
     * @return void
     */
    public function traceExecuteBulkWrite($class, $method)
    {
        $integration = $this;
        \DDTrace\trace_method($class, $method, function ($span, $args) use ($integration) {
            list($database, $collection) = MongoDBIntegration::parseNamespace(isset($args[0]) ? $args[0] : null);

            $integration->setMetadata(
                $span,
                'mongodb.driver.cmd',
                'executeBulkWrite',
                $database,
                $collection,
                null,
                null,
                null,
                null
            );

            if (isset($args[1])) {
                $deletes = ObjectKVStore::get($args[1], 'deletes', []);
                for ($index = 0; $index < \count($deletes); $index++) {
                    $span->meta['mongodb.deletes.' . $index . '.filter'] = $deletes[$index];
                }

                $updates = ObjectKVStore::get($args[1], 'updates', []);
                for ($index = 0; $index < \count($updates); $index++) {
                    $span->meta['mongodb.updates.' . $index . '.filter'] = $updates[$index];
                }

                $insertsCount = ObjectKVStore::get($args[1], 'insertsCount', 0);
                $span->meta['mongodb.insertsCount'] = $insertsCount;
            }
        });
    }

    /**
     * Traces Manager/Server::executeCommand
     *
     * @param string $class
     * @param string $method
     * @return void
     */
    public function traceExecuteCommand($class, $method, $knownCommands)
    {
        $integration = $this;
        \DDTrace\trace_method($class, $method, function ($span, $args) use ($method, $knownCommands, $integration) {
            // DB name
            $dbName = 'unknown_db';
            if (isset($args[0]) && \is_string($args[0])) {
                $dbName = $args[0];
            }

            // Collection name
            $collection = null;
            $commandName = 'unknown_command';
            if (
                isset($args[1])
                && ($command = ObjectKVStore::get($args[1], 'cmd'))
                && (\is_array($command) || \is_object($command))
            ) {
                $command = (array)$command;
                $cmdKeys = \array_keys($command);
                $realCmd = \array_intersect($cmdKeys, $knownCommands);
                if (\count($realCmd) === 1) {
                    $commandName = $realCmd[0];
                    $collectionCandidate = $command[$realCmd[0]];
                    if (\is_string($collectionCandidate)) {
                        $collection = $collectionCandidate;
                    }
                }
            }

            $integration->setMetadata(
                $span,
                'mongodb.driver.cmd',
                $method,
                $dbName,
                $collection,
                null,
                null,
                $commandName,
                null
            );
        });
    }

    /**
     * Attempts at serializing a query/filter as a best effort, avoiding generating errors.
     *
     * @param mixed $anythingQueryLike
     * @return null|string
     */
    public static function serializeQuery($anythingQueryLike)
    {
        if (!$anythingQueryLike) {
            return null;
        }
        $normalizedQuery = MongoDBIntegration::normalizeQuery($anythingQueryLike);
        $jsonFlags = JSON_UNESCAPED_UNICODE;
        if (\PHP_VERSION_ID >= 70200) {
            $jsonFlags = $jsonFlags | JSON_INVALID_UTF8_SUBSTITUTE;
        }
        return (null === $normalizedQuery) ? '?' : \json_encode($normalizedQuery, $jsonFlags);
    }

    public static function normalizeQuery($rawQuery)
    {
        if (null === $rawQuery) {
            return null;
        }

        $queryAsArray = null;

        if ($rawQuery instanceof \stdClass) {
            $queryAsArray = (array)$rawQuery;
        } elseif (\is_object($rawQuery)) {
            // We ignore `MongoDB namespace`
            if (\strpos(\get_class($rawQuery), 'MongoDB') === 0) {
                return '?';
            }

            $queryAsArray = (array)$rawQuery;
        } elseif (\is_array($rawQuery)) {
            $queryAsArray = $rawQuery;
        } else {
            return '?';
        }

        $normalized = [];

        foreach ($queryAsArray as $key => $value) {
            if ('$in' === $key || '$nin' === $key) {
                $normalized[$key] = "?";
            } elseif (\is_array($value) || \is_object($value)) {
                $normalized[$key] = MongoDBIntegration::normalizeQuery($value);
            } else {
                $normalized[$key] = '?';
            }
        }

        return $normalized ?: null;
    }

    /**
     * Given a namespace string 'db.collection', it parses the database name and the collection name.
     *
     * @param string $namespace
     * @return [$db, $collection]
     */
    public static function parseNamespace($namespace)
    {
        if (!$namespace) {
            return ['unknown_database', 'unknown_collection'];
        }

        /* I could not find any restrictions for db and collection names in official docs
         * (https://docs.mongodb.com/manual/core/databases-and-collections/#databases-and-collections)
         * Empirically - using mongosh - I can say
         *   - db names cannot contain dots (MongoshInvalidInputError: [COMMON-10001] Invalid database name: db.dots)
         *   - collection names can contain dots
         *         > db.createCollection('with.dots');
         *           { ok: 1 }
         *   - database name and collection name are connected by a single dot char '.' in a namespace.
         * So we explode and consider the first fragment as the 'database name', all the other fragments as the
         * 'collection name'.
         */
        $parts = \explode('.', $namespace);

        $remainings = \array_slice($parts, 1);
        return [$parts[0], $remainings ? \implode(' ', $remainings) : 'unknown_collection'];
    }

    /**
     * Sets all relevant metadata in a consistent way
     *
     * @param DDTrace\SpanData $span
     * @param string $name
     * @param string $method
     * @param string|null $database If null the corresponding metadata will  not be set.
     * @param string|null $collection If null the corresponding metadata will  not be set.
     * @param string|null $host If null the corresponding metadata will  not be set.
     * @param int|string|null $port If null the corresponding metadata will  not be set.
     * @param string|null $command If null the corresponding metadata will  not be set.
     * @param mixed|null $rawQuery If null the corresponding metadata will  not be set.
     * @return void
     */
    public function setMetadata(
        SpanData $span,
        $name,
        $method,
        $database,
        $collection,
        $host,
        $port,
        $command,
        $rawQuery
    ) {
        $span->name = $name;
        $span->service = 'mongodb';
        $span->type = Type::MONGO;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $serializedQuery = $rawQuery ? MongoDBIntegration::serializeQuery($rawQuery) : null;
        $span->resource = \implode(' ', array_filter([$method, $database, $collection, $command, $serializedQuery]));
        if ($database) {
            $span->meta[Tag::MONGODB_DATABASE] = $database;
        }
        if ($collection) {
            $span->meta[Tag::MONGODB_COLLECTION] = $collection;
        }
        if ($host) {
            $span->meta[Tag::TARGET_HOST] = $host;
        }
        if ($port) {
            $span->meta[Tag::TARGET_PORT] = $port;
        }
        if ($serializedQuery) {
            $span->meta[Tag::MONGODB_QUERY] = $serializedQuery;
        }
        $this->addTraceAnalyticsIfEnabled($span);
    }
}
