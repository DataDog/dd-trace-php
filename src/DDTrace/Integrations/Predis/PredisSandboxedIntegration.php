<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\AbstractConnection;

class PredisSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'predis';

    /**
     * @var array
     */
    private static $connections = [];

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        $integration = $this;

        \dd_trace_method('Predis\Client', '__construct', function (SpanData $span, $args) {
            $span->name = 'Predis.Client.__construct';
            $span->type = Type::CACHE;
            $span->service = 'redis';
            $span->resource = 'Predis.Client.__construct';
            PredisSandboxedIntegration::storeConnectionParams($this, $args);
            PredisSandboxedIntegration::setConnectionTags($this, $span);
        });

        \dd_trace_method('Predis\Client', 'connect', function (SpanData $span, $args) {
            $span->name = 'Predis.Client.connect';
            $span->type = Type::CACHE;
            $span->service = 'redis';
            $span->resource = 'Predis.Client.connect';
            PredisSandboxedIntegration::setConnectionTags($this, $span);
        });

        \dd_trace_method('Predis\Client', 'executeCommand', function (SpanData $span, $args) use ($integration) {
            $span->name = 'Predis.Client.executeCommand';
            $span->type = Type::CACHE;
            $span->service = 'redis';
            PredisSandboxedIntegration::setConnectionTags($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);

            // We default resource name to 'Predis.Client.executeCommand', but if we are able below to extract the query
            // then we replace it with the query
            $span->resource = 'Predis.Client.executeCommand';

            if (\count($args) == 0) {
                return;
            }

            $command = $args[0];
            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $span->meta['redis.args_length'] = count($arguments);
            $query = PredisSandboxedIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.raw_command'] = $query;
        });

        \dd_trace_method('Predis\Client', 'executeRaw', function (SpanData $span, $args) use ($integration) {
            $span->name = 'Predis.Client.executeRaw';
            $span->type = Type::CACHE;
            $span->service = 'redis';
            PredisSandboxedIntegration::setConnectionTags($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);

            // We default resource name to 'Predis.Client.executeRaw', but if we are able below to extract the query
            // then we replace it with the query
            $span->resource = 'Predis.Client.executeRaw';

            if (\count($args) == 0) {
                return;
            }
            $arguments = $args[0];
            $query = PredisSandboxedIntegration::formatArguments($arguments);
            $span->resource = $query;
            $span->meta['redis.args_length'] = count($arguments);
            $span->meta['redis.raw_command'] = $query;
        });

        // PHP 5 does not support prehook, which is required to get the pipeline count before
        // tasks are dequeued.
        if (Versions::phpVersionMatches('5')) {
            \dd_trace_method('Predis\Pipeline\Pipeline', 'executePipeline', function (SpanData $span, $args) {
                $span->name = 'Predis.Pipeline.executePipeline';
                $span->resource = $span->name;
                $span->type = Type::CACHE;
                $span->service = 'redis';
                PredisSandboxedIntegration::setConnectionTags($this, $span);
            });
        } else {
            \dd_trace_method(
                'Predis\Pipeline\Pipeline',
                'executePipeline',
                [
                    'prehook' => function (SpanData $span, $args) {
                        $span->name = 'Predis.Pipeline.executePipeline';
                        $span->resource = $span->name;
                        $span->type = Type::CACHE;
                        $span->service = 'redis';
                        PredisSandboxedIntegration::setConnectionTags($this, $span);
                        if (\count($args) < 2) {
                            return;
                        }
                        $commands = $args[1];
                        $span->meta['redis.pipeline_length'] = count($commands);
                    },
                ]
            );
        }

        return SandboxedIntegration::LOADED;
    }

    /**
     * This function is a clone of PredisIntegration::storeConnectionParams.
     * Store connection params to be reused to tags following predids queries.
     *
     * @param Predis\Client $predis
     * @param array $args
     * @return void
     */
    public static function storeConnectionParams($predis, $args)
    {
        $tags = [];

        $connection = $predis->getConnection();

        if ($connection instanceof AbstractConnection) {
            $connectionParameters = $connection->getParameters();

            $tags[Tag::TARGET_HOST] = $connectionParameters->host;
            $tags[Tag::TARGET_PORT] = $connectionParameters->port;
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

        self::$connections[spl_object_hash($predis)] = $tags;
    }

    /**
     * This function is almost a clone of PredisIntegration::setConnectionTags.
     * Store connection tags into a successive span not having direct access to those values.
     *
     * @param Predis\Client $predis
     * @param DDTrace\SpanData $span
     * @return void
     */
    public static function setConnectionTags($predis, SpanData $span)
    {
        $hash = spl_object_hash($predis);
        if (!isset(self::$connections[$hash])) {
            return;
        }

        foreach (self::$connections[$hash] as $tag => $value) {
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
