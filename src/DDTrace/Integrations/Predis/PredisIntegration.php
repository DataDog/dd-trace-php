<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\NodeConnectionInterface;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class PredisIntegration extends Integration
{
    const NAME = 'predis';
    const SYSTEM = 'redis';

    const DEFAULT_SERVICE_NAME = 'redis';

    /**
     * Add instrumentation to PDO requests
     */
    public static function init(): int
    {
        // __construct: always store connection metadata (needed for executeCommand tags),
        // but only create a span when lifecycle commands are enabled
        \DDTrace\install_hook(
            'Predis\Client::__construct',
            // Prehook: create span before constructor runs (so it covers the entire execution)
            static function (HookData $hook) {
                if (\dd_trace_env_config("DD_TRACE_REDIS_LIFECYCLE_COMMANDS_ENABLED")) {
                    $hook->span(); // Create span now, before constructor body runs
                    $hook->data = true;
                }
            },
            // Posthook: constructor has completed, safe to call getConnection()
            static function (HookData $hook) {
                PredisIntegration::storeConnectionMetaAndService($hook->instance, $hook->args);

                if (!isset($hook->data)) {
                    return;
                }

                $span = $hook->span();
                Integration::handleOrphan($span);
                $span->name = 'Predis.Client.__construct';
                $span->type = Type::REDIS;
                $span->resource = 'Predis.Client.__construct';
                PredisIntegration::setMetaAndServiceFromConnection($hook->instance, $span);
            }
        );

        // connect: lifecycle-only span
        \DDTrace\install_hook('Predis\Client::connect', static function (HookData $hook) {
            if (!\dd_trace_env_config("DD_TRACE_REDIS_LIFECYCLE_COMMANDS_ENABLED")) {
                return;
            }

            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = 'Predis.Client.connect';
            $span->type = Type::REDIS;
            $span->resource = 'Predis.Client.connect';
            PredisIntegration::setMetaAndServiceFromConnection($hook->instance, $span);
        });

        // executeCommand: always traced (data command)
        \DDTrace\trace_method('Predis\Client', 'executeCommand', function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.executeCommand';
            $span->type = Type::REDIS;
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
            PredisIntegration::addTraceAnalyticsIfEnabled($span);

            $span->resource = 'Predis.Client.executeCommand';

            if (\count($args) == 0) {
                return;
            }

            $command = $args[0];
            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $span->meta['redis.args_length'] = count($arguments);
            $query = PredisIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.raw_command'] = $query;
        });

        // executeRaw: always traced (data command)
        \DDTrace\trace_method('Predis\Client', 'executeRaw', function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            $span->name = 'Predis.Client.executeRaw';
            $span->type = Type::REDIS;
            PredisIntegration::setMetaAndServiceFromConnection($this, $span);
            PredisIntegration::addTraceAnalyticsIfEnabled($span);

            $span->resource = 'Predis.Client.executeRaw';

            if (\count($args) == 0) {
                return;
            }
            $arguments = $args[0];
            $query = PredisIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.args_length'] = count($arguments);
            $span->meta['redis.raw_command'] = $query;
        });

        // executePipeline: lifecycle-only span
        \DDTrace\install_hook(
            'Predis\Pipeline\Pipeline::executePipeline',
            static function (HookData $hook) {
                if (!\dd_trace_env_config("DD_TRACE_REDIS_LIFECYCLE_COMMANDS_ENABLED")) {
                    return;
                }

                $span = $hook->span();
                Integration::handleOrphan($span);
                $span->name = 'Predis.Pipeline.executePipeline';
                $span->resource = $span->name;
                $span->type = Type::REDIS;
                // getClient() is on the Pipeline instance
                PredisIntegration::setMetaAndServiceFromConnection($hook->instance->getClient(), $span);
                $args = $hook->args;
                if (\count($args) < 2) {
                    return;
                }
                $commands = $args[1];
                $span->meta['redis.pipeline_length'] = count($commands);
            }
        );

        return Integration::LOADED;
    }

    /**
     * This function is a clone of PredisIntegration::storeConnectionParams.
     * Store connection params to be reused to tags following predids queries.
     *
     * @param Predis\Client $predis
     * @param array $args
     * @return void
     */
    public static function storeConnectionMetaAndService($predis, $args)
    {
        $tags = [];
        $connection = $predis->getConnection();

        if ($connection instanceof NodeConnectionInterface) {
            $connectionParameters = $connection->getParameters();

            $tags[Tag::TARGET_HOST] = $connectionParameters->host;
            $tags[Tag::TARGET_PORT] = $connectionParameters->port;

            if (\dd_trace_env_config("DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST")) {
                $service = \DDTrace\Util\Normalizer::normalizeHostUdsAsService(
                    'redis-' . (isset($connectionParameters->path)
                        ? $connectionParameters->path
                        : $connectionParameters->host)
                );
                ObjectKVStore::put($predis->getConnection(), 'service', $service);
            }
        }

        if (isset($args[1])) {
            $options = $args[1];

            if (is_array($options)) {
                $parameters = isset($options['parameters']) ? $options['parameters'] : [];
            } elseif ($options instanceof OptionsInterface) {
                $parameters = $options->__get('parameters') ?: [];
            }

            if (is_array($parameters) && isset($parameters['database'])) {
                $tags['out.redis_db'] = $parameters['database'];
            }
        }

        ObjectKVStore::put($predis->getConnection(), 'connection_meta', $tags);
    }

    /**
     * This function is almost a clone of PredisIntegration::setConnectionTags.
     * Store connection tags into a successive span not having direct access to those values.
     *
     * @param Predis\Client $predis
     * @param DDTrace\SpanData $span
     * @return void
     */
    public static function setMetaAndServiceFromConnection($predis, SpanData $span)
    {
        $service = ObjectKVStore::get($predis->getConnection(), 'service');
        if ($service) {
            $span->meta[Tag::SERVICE_NAME] = $service;
        } else {
            Integration::handleInternalSpanServiceName($span, self::DEFAULT_SERVICE_NAME);
        }
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::SYSTEM;

        foreach (ObjectKVStore::get($predis->getConnection(), 'connection_meta', []) as $tag => $value) {
            $span->meta[$tag] = $value;
        }
    }

    /**
     * This function is a clone of PredisIntegration::formatArguments.
     * Format a command by removing unwanted values
     *
     * Restrict what we keep from the values sent (with a SET, HGET, LPUSH, ...):
     * - Skip binary content
     * - Truncate
     */
    public static function formatArguments($arguments)
    {
        $len = 0;
        $out = [];

        foreach ($arguments as $argument) {
            // crude test to skip binary
            if (strpos($argument, "\0") !== false) {
                continue;
            }

            $cmd = (string)$argument;

            if (strlen($cmd) > VALUE_MAX_LEN) {
                $cmd = substr($cmd, 0, VALUE_MAX_LEN) . VALUE_TOO_LONG_MARK;
            }

            if (($len + strlen($cmd)) > CMD_MAX_LEN) {
                $prefix = substr($cmd, 0, CMD_MAX_LEN - $len);
                $out[] = $prefix . VALUE_TOO_LONG_MARK;
                break;
            }

            $out[] = $cmd;
            $len += strlen($cmd);
        }

        return implode(' ', $out);
    }
}
