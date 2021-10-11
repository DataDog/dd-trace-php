<?php

namespace DDTrace\Integrations\MongoDB;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

/**
 * Defines and register a subscriber. It is done in a function, rather than in a separate file
 */
function register_subscriber()
{
    class DatadogSubscriber implements \MongoDB\Driver\Monitoring\CommandSubscriber
    {
        public function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event)
        {
            $span = \DDTrace\active_span();
            if ($span) {
                // error_log('Command: ' . var_export(get_class_methods($event), true));
                // $span->meta[Tag::MONGODB_COLLECTION] = $this->getCollectionName();
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
        // We have two methods that trigger the integrations to be loaded.
        // Integration loading should be cached, for now we keep track of the initialization execution.
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!extension_loaded('mongodb')) {
            return Integration::NOT_AVAILABLE;
        }

        register_subscriber();

        \DDTrace\hook_method(
            'MongoDB\Driver\Manager',
            'selectServer',
            null,
            function ($self, $_2, $_3, $server) {
                ObjectKVStore::put($self, 'host', $server->getHost());
                ObjectKVStore::put($self, 'port', $server->getPort());
            }
        );

        \DDTrace\trace_method(
            'MongoDB\Driver\Manager',
            'executeQuery',
            function ($span, $args) {
                $namespace = $args[0];
                $resourceParts = ['executeQuery', $namespace];
                if ($filter = ObjectKVStore::get($args[1], 'filter')) {
                    $serializedQuery = MongoDBIntegration::serializeQuery($filter);
                    \array_push($resourceParts, $serializedQuery);
                }

                $span->name = 'mongodb.driver.cmd';
                $span->service = 'mongodb';
                $span->type = Type::MONGO;
                $span->resource = \implode(' ', $resourceParts);
                $span->meta[Tag::SPAN_KIND] = 'client';

                list($database, $collection) = MongoDBIntegration::parseNamespace($namespace);
                $span->meta[Tag::MONGODB_DATABASE] = $database;
                $span->meta[Tag::MONGODB_COLLECTION] = $collection;
                if (!empty($serializedQuery)) {
                    $span->meta[Tag::MONGODB_QUERY] = $serializedQuery;
                }
            }
        );

        \DDTrace\trace_method(
            'MongoDB\Driver\Manager',
            'executeCommand',
            function ($span, $args) {
                error_log('executeCommand');
                // error_log('DB  : ' . var_export($args[0], true));
                // error_log('Cmd : ' . var_export($args[1], true));
                // error_log('Mtds: ' . var_export(get_class_methods($args[1]), true));

                // $knownCommands = [
                //     'aggregate',
                //     'count',
                //     'distinct',
                //     'mapReduce',
                //     'geoSearch',
                //     'delete',
                //     'find',
                //     'findAndModify',
                //     'getLastError',
                //     'getMore',
                //     'insert',
                //     'resetError',
                //     'update',
                //     'planCacheClear',
                //     'planCacheClearFilters',
                //     'planCacheListFilters',
                //     'planCacheSetFilter',
                //     'authenticate',
                //     'getnonce',
                //     'logout',
                //     'createUser',
                //     'dropAllUsersFromDatabase',
                //     'dropUser',
                //     'grantRolesToUser',
                //     'revokeRolesFromUser',
                //     'updateUser',
                //     'usersInfo',
                //     'createRole',
                //     'dropRole',
                //     'dropAllRolesFromDatabase',
                //     'grantPrivilegesToRole',
                //     'grantRolesToRole',
                //     'invalidateUserCache',
                //     'revokePrivilegesFromRole',
                //     'revokeRolesFromRole',
                //     'rolesInfo',
                //     'updateRole',
                //     'applyOps',
                //     'hello',
                //     'replSetAbortPrimaryCatchUp',
                //     'replSetFreeze',
                //     'replSetGetConfig',
                //     'replSetGetStatus',
                //     'replSetInitiate',
                //     'replSetMaintenance',
                //     'RECOVERING',
                //     'replSetReconfig',
                //     'replSetResizeOplog',
                //     'replSetStepDown',
                //     'replSetSyncFrom',
                //     'abortReshardCollection',
                //     'addShard',
                //     'addShardToZone',
                //     'balancerCollectionStatus',
                //     'balancerStart',
                //     'balancerStatus',
                //     'balancerStop',
                //     'checkShardingIndex',
                //     'clearJumboFlag',
                //     'jumbo',
                //     'cleanupOrphaned',
                //     'cleanupReshardCollection',
                //     'commitReshardCollection',
                //     'enableSharding',
                //     'flushRouterConfig',
                //     'mongod',
                //     'mongos',
                //     'getShardMap',
                //     'getShardVersion',
                //     'isdbgrid',
                //     'mongos',
                //     'listShards',
                //     'medianKey',
                //     'splitVector',
                //     'moveChunk',
                //     'movePrimary',
                //     'mergeChunks',
                //     'refineCollectionShardKey',
                //     'removeShard',
                //     'removeShardFromZone',
                //     'reshardCollection',
                //     'setShardVersion',
                //     'shardCollection',
                //     'shardingState',
                //     'mongod',
                //     'split',
                //     'splitChunk',
                //     'sh.splitFind()',
                //     'sh.splitAt()',
                //     'splitVector',
                //     'unsetSharding',
                //     'updateZoneKeyRange',
                //     'abortTransaction',
                //     'commitTransaction',
                //     'endSessions',
                //     'killAllSessions',
                //     'killAllSessionsByPattern',
                //     'killSessions',
                //     'refreshSessions',
                //     'startSession',
                //     'cloneCollectionAsCapped',
                //     'collMod',
                //     'compact',
                //     'connPoolSync',
                //     'convertToCapped',
                //     'create',
                //     'createIndexes',
                //     'currentOp',
                //     'drop',
                //     'dropDatabase',
                //     'dropConnections',
                //     'dropIndexes',
                //     'filemdfsync',
                //     'fsyncUnlock',
                //     'getDefaultRWConcern',
                //     'getParameter',
                //     'killCursors',
                //     'killOp',
                //     'listCollections',
                //     'listDatabases',
                //     'listIndexes',
                //     'logRotate',
                //     'reIndex',
                //     'renameCollection',
                //     'rotateCertificates',
                //     'setFeatureCompatibilityVersion',
                //     'setIndexCommitQuorum',
                //     'setParameter',
                //     'setDefaultRWConcern',
                //     'shutdown',
                //     'mongod',
                //     'mongos',
                //     'availableQueryOptions',
                //     'buildInfo',
                //     'collStats',
                //     'connPoolStats',
                //     'connectionStatus',
                //     'cursorInfo',
                //     'metrics.cursor',
                //     'dataSize',
                //     'dbHash',
                //     'dbStats',
                //     'driverOIDTest',
                //     'explain',
                //     'features',
                //     'getCmdLineOpts',
                //     'getLog',
                //     'hostInfo',
                //     'lockInfo',
                //     'mongod',
                //     'netstat',
                //     'mongos',
                //     'ping',
                //     'profile',
                //     'serverStatus',
                //     'shardConnPoolStats',
                //     'connPoolStats',
                //     'top',
                //     'mongod',
                //     'validate',
                //     'whatsmyuri',
                //     'setFreeMonitoring',
                //     'logApplicationMessage',
                // ];

                // if ($command = ObjectKVStore::get($args[0], 'cmd')) {
                //     $cmdKeys = \array_keys($command);
                //     $realCmd = \array_intersect($cmdKeys, $knownCommands);
                //     if (\count($realCmd) === 1) {
                //         error_log('HHHHHHHHHHHHHHHHHHHH: ' . var_export($realCmd[0], true));
                //     }
                // }



                // // $resourceParts = ['executeQuery', $namespace];
                // $span->name = 'mongodb.driver.cmd';
                // $span->service = 'mongodb';
                // $span->type = Type::MONGO;
                // $span->resource = \implode(' ', $resourceParts);
                // $span->meta[Tag::SPAN_KIND] = 'client';

                // // list($database, $collection) = MongoDBIntegration::parseNamespace($namespace);
                // $span->meta[Tag::MONGODB_DATABASE] = $database;
                // $span->meta[Tag::MONGODB_COLLECTION] = $collection;
                // if (!empty($serializedQuery)) {
                //     $span->meta[Tag::MONGODB_QUERY] = $serializedQuery;
                // }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\Query',
            '__construct',
            null,
            function ($self, $_2, $args, $_4) {
                // \error_log('kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk');
                // error_log('Filter from query is: ' . var_export($args[0], true));
                // // ObjectKVStore::put($self, 'port', $server->getPort());
                if (isset($args[0]) and \is_array($args[0])) {
                    ObjectKVStore::put($self, 'filter', $args[0]);
                }
            }
        );

        \DDTrace\hook_method(
            'MongoDB\Driver\Command',
            '__construct',
            null,
            function ($self, $_2, $args, $_4) {
                \error_log('kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk');
                // error_log('Filter from query is: ' . var_export($args[0], true));
                // // ObjectKVStore::put($self, 'port', $server->getPort());
                // if (isset($args[0]) and \is_array($args[0])) {
                //     ObjectKVStore::put($self, 'cmd', $args[0]);
                // }
            }
        );
        error_log('loading....');
        \DDTrace\hook_method('MongoDB\Driver\Query', '__construct', null, function ($self, $_1, $args) {
            error_log('>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>');
            error_log('$1: ' . var_export($_1, true));
            error_log('Args: ' . var_export($args, true));
        });

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
            MongoDBIntegration::traceCollectionMethodWithFilter($method);
        }

        $collectionMethodsNoFilter = [
            'drop',
            'dropIndexes',
            'estimatedDocumentCount',
            'insertMany',
            'insertOne',
            'listIndexes',
            'mapReduce',
        ];

        foreach ($collectionMethodsNoFilter as $method) {
            MongoDBIntegration::traceCollectionMethodNoArgs($method);
        }

        return Integration::LOADED;
    }

    public static function traceCollectionMethodWithFilter($method)
    {
        \DDTrace\trace_method('MongoDB\Collection', $method, function (SpanData $span, $args) use ($method) {
            $span->name = 'mongodb.cmd';
            $span->service = 'mongodb';
            $span->type = Type::MONGO;
            $span->meta[Tag::SPAN_KIND] = 'client';

            $span->meta[Tag::MONGODB_DATABASE] = $this->getDatabaseName();
            $span->meta[Tag::MONGODB_COLLECTION] = $this->getCollectionName();
            $normalizedQuery = MongoDBIntegration::normalizeQuery($args[0]);

            $resourceNameParts = [$method, $this->getDatabaseName(), $this->getCollectionName()];
            if ($normalizedQuery) {
                $serializedQuery = (null === $normalizedQuery) ? '{}' : \json_encode($normalizedQuery);
                \array_push($resourceNameParts, $serializedQuery);
                $span->meta[Tag::MONGODB_QUERY] = $serializedQuery;
            }
            $span->resource = \implode(' ', $resourceNameParts);

            $manager = $this->getManager();
            $span->meta[Tag::TARGET_HOST] = ObjectKVStore::get($manager, 'host');
            $span->meta[Tag::TARGET_PORT] = ObjectKVStore::get($manager, 'port');
        });
    }

    public static function traceCollectionMethodNoArgs($method)
    {
        \DDTrace\trace_method('MongoDB\Collection', $method, function (SpanData $span, $args) use ($method) {
            $span->name = 'mongodb.cmd';
            $span->service = 'mongodb';
            $span->type = Type::MONGO;
            $span->meta[Tag::SPAN_KIND] = 'client';

            $span->meta[Tag::MONGODB_DATABASE] = $this->getDatabaseName();
            $span->meta[Tag::MONGODB_COLLECTION] = $this->getCollectionName();

            $span->resource = $method . ' ' . $this->getDatabaseName() . ' ' . $this->getCollectionName();

            $manager = $this->getManager();
            $span->meta[Tag::TARGET_HOST] = ObjectKVStore::get($manager, 'host');
            $span->meta[Tag::TARGET_PORT] = ObjectKVStore::get($manager, 'port');
        });
    }

    public static function serializeQuery($anythingQueryLike)
    {
        $normalizedQuery = MongoDBIntegration::normalizeQuery($anythingQueryLike);
        return (null === $normalizedQuery) ? '?' : \json_encode($normalizedQuery);
    }

    public static function normalizeQuery($rawQuery)
    {

        if (null === $rawQuery) {
            return null;
        }

        $queryAsArray = null;

        if (\is_a($rawQuery, 'stdClass')) {
            $queryAsArray = get_object_vars($rawQuery);
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

        return empty($normalized) ? null : $normalized;
    }

    /**
     * Given a namespace string (db.collection),parses the database name and the collection name.
     *
     * @param string $namespace
     * @return [$db, $collection]
     */
    public static function parseNamespace($namespace)
    {
        /* I could not find any restrictions in db and collection names in official docs
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

        // Only the namespace
        if (\count($parts) === 1) {
            return [$parts[0]];
        }

        return [$parts[0], \implode('.', \array_slice($parts, 1))];
    }
}
