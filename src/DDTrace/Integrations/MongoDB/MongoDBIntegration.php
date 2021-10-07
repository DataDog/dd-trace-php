<?php

namespace DDTrace\Integrations\MongoDB;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class MongoDBIntegration extends Integration
{
    const NAME = 'mongodb';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!extension_loaded('mongodb')) {
            return Integration::NOT_AVAILABLE;
        }

        \DDTrace\hook_method(
            'MongoDB\Driver\Manager',
            'selectServer',
            null,
            function ($self, $_2, $_3, $server) {
                ObjectKVStore::put($self, 'host', $server->getHost());
                ObjectKVStore::put($self, 'port', $server->getPort());
            }
        );

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
}
