<?php

namespace DDTrace\Integrations\MongoDB;

use MongoDB\Driver\Monitoring\CommandSubscriber;
use DDTrace\Type;
use DDTrace\Tag;

class MongoDBSubscriber implements CommandSubscriber
{

    public function commandFailed($ev)
    {
        //\DDTrace\close_span();
        error_log('>>>>>>>>>>>>Failed: ' . var_export($ev, 1));
    }

    public function commandStarted($ev)
    {
        return;
        //MongoDBSubscriber::describeEventForDeveloping($ev);
        $command = $ev->getCommand();

        $span = \DDTrace\start_span();
        $span->name = 'mongodb.cmd';

        $commandName = $ev->getCommandName();
        $resourceParts = [$commandName, $ev->getDatabaseName()];
        if (\property_exists($command, $commandName)) {
            \array_push($resourceParts, $command->$commandName);
        }
        if ($normalizedQuery = MongoDBSubscriber::extractQuery($command, $commandName)) {
            \array_push($resourceParts, $normalizedQuery);
        }

        $span->resource = \implode(' ', $resourceParts);
        $span->type = Type::MONGO;
        $span->service = 'mongodb';

        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::MONGODB_DATABASE] = $ev->getDatabaseName();
        // $span->meta[Tag::MONGODB_COLLECTION] = $ev->getServer()->getPort();
        $span->meta[Tag::TARGET_HOST] = $ev->getServer()->getHost();
        $span->meta[Tag::TARGET_PORT] = $ev->getServer()->getPort();

        if ($normalizedQuery) {
            $span->meta[Tag::MONGODB_QUERY] = $normalizedQuery;
        }
    }

    public function commandSucceeded($ev)
    {
        return;
        \DDTrace\close_span();
    }


    public static function describeEventForDeveloping($ev)
    {
        error_log('Metadata: ' . var_export([
            'event properties' => \get_object_vars($ev),
            'event methods' => \get_class_methods($ev),
            'commandName' => $ev->getCommandName(),
            'command methods' => get_class_methods($ev->getCommand()),
            'command properties' => \get_object_vars($ev->getCommand()),
            'host' => $ev->getServer()->getHost(),
            'port' => $ev->getServer()->getPort(),
            'server metods' => get_class_methods(
                $ev->getServer()
            ),
        ], true));
    }


    // public static function extractQuery($command, $commandName)
    // {
    //     if (\property_exists($command, 'filter')) {
    //         $normalized = MongoDBIntegration::normalizeQuery($command->filter);
    //         return (null === $normalized) ? '{}' : \json_encode($normalized);
    //     }


    //     if (
    //         \property_exists($command, $expectedQueriesFieldName = "${commandName}s")
    //         && \is_array($filters = $command->$expectedQueriesFieldName)
    //     ) {
    //         $query = [];
    //         foreach ($filters as $filter) {
    //             if (!\property_exists($filter, 'q')) {
    //                 continue;
    //             }

    //             \array_push($query, MongoDBIntegration::normalizeQuery($filter->q));
    //         }
    //         if (\count($query) === 0) {
    //             return '{}';
    //         } elseif (\count($query) === 1) {
    //             return \json_encode($query[0]);
    //         } else {
    //             return \json_encode($query);
    //         }
    //     }

    //     return null;
    // }

    // public static function normalizeQuery($rawQuery)
    // {

    //     if (null === $rawQuery) {
    //         return null;
    //     }

    //     $queryAsArray = null;

    //     if (\is_a($rawQuery, 'stdClass')) {
    //         $queryAsArray = get_object_vars($rawQuery);
    //     } else if (\is_array($rawQuery)) {
    //         $queryAsArray = $rawQuery;
    //     } else {
    //         return '?';
    //     }

    //     $normalized = [];

    //     foreach ($queryAsArray as $key => $value) {
    //         if ('$in' === $key || '$nin' === $key) {
    //             $normalized[$key] = "?";
    //         } elseif (\is_array($value) || \is_object($value)) {
    //             $normalized[$key] = MongoDBIntegration::normalizeQuery($value);
    //         } else {
    //             $normalized[$key] = '?';
    //         }
    //     }

    //     return empty($normalized) ? null : $normalized;
    // }
}
