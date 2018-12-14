<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;
use Predis\Pipeline\Pipeline;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class PredisIntegration
{
    const NAME = 'predis';

    /**
     * @var array
     */
    private static $connections = [];

    /**
     * Static method to add instrumentation to the Predis library
     */
    public static function load()
    {
        if (!class_exists('\Predis\Client')) {
            return;
        }

        // public Predis\Client::__construct ([ mixed $dsn [, mixed $options ]] )
        dd_trace('\Predis\Client', '__construct', function () {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.__construct');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\CACHE);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag(Tags\RESOURCE_NAME, 'Predis.Client.__construct');
            try {
                call_user_func_array([$this, '__construct'], $args);
                PredisIntegration::storeConnectionParams($this, $args);
                PredisIntegration::setConnectionTags($this, $span);
                return $this;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public void Predis\Client::connect()
        dd_trace('\Predis\Client', 'connect', function () {
            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.connect');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\CACHE);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag(Tags\RESOURCE_NAME, 'Predis.Client.connect');
            PredisIntegration::setConnectionTags($this, $span);

            try {
                return $this->connect();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public mixed Predis\Client::executeCommand(CommandInterface $command)
        dd_trace('\Predis\Client', 'executeCommand', function ($command) {
            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $query = PredisIntegration::formatArguments($arguments);

            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.executeCommand');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\CACHE);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($arguments));
            $span->setTag(Tags\RESOURCE_NAME, $query);
            PredisIntegration::setConnectionTags($this, $span);

            try {
                return $this->executeCommand($command);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // Predis < 1 has not this method
        if (method_exists('\Predis\Client', 'executeRaw')) {
            // public mixed Predis\Client::executeRaw(array $arguments, bool &$error)
            dd_trace('\Predis\Client', 'executeRaw', function ($arguments, &$error = null) {
                $query = PredisIntegration::formatArguments($arguments);

                $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.executeRaw');
                $span = $scope->getSpan();
                $span->setTag(Tags\SPAN_TYPE, Types\CACHE);
                $span->setTag(Tags\SERVICE_NAME, 'redis');
                $span->setTag('redis.raw_command', $query);
                $span->setTag('redis.args_length', count($arguments));
                $span->setTag(Tags\RESOURCE_NAME, $query);
                PredisIntegration::setConnectionTags($this, $span);

                try {
                    return $this->executeRaw($arguments, $error);
                } catch (\Exception $e) {
                    $span->setError($e);
                    throw $e;
                } finally {
                    $scope->close();
                }
            });
        }

        // Predis < 1 has not this method
        if (method_exists('\Predis\Pipeline\Pipeline', 'executePipeline')) {
            // protected array Predis\Pipeline::executePipeline(ConnectionInterface $connection, \SplQueue $commands)
            dd_trace(Pipeline::class, 'executePipeline', function ($connection, $commands) {
                $scope = GlobalTracer::get()->startActiveSpan('Predis.Pipeline.executePipeline');
                $span = $scope->getSpan();
                $span->setTag(Tags\SPAN_TYPE, Types\CACHE);
                $span->setTag(Tags\SERVICE_NAME, 'redis');
                $span->setTag('redis.pipeline_length', count($commands));
                PredisIntegration::setConnectionTags($this, $span);

                try {
                    return $this->executePipeline($connection, $commands);
                } catch (\Exception $e) {
                    $span->setError($e);
                    throw $e;
                } finally {
                    $scope->close();
                }
            });
        }
    }

    public static function storeConnectionParams($predis, $args)
    {
        $tags = [];

        try {
            $identifier = (string)$predis->getConnection();
            list($host, $port) = explode(':', $identifier);
            $tags[Tags\TARGET_HOST] = $host;
            $tags[Tags\TARGET_PORT] = $port;
        } catch (\Exception $e) {
        }

        if (isset($args[1])) {
            $options = $args[1];

            $parameters = $options->__get('parameters');

            if (is_array($parameters) && isset($parameters['database'])) {
                $tags['out.redis_db'] = $parameters['database'];
            }
        }

        self::$connections[spl_object_hash($predis)] = $tags;
    }

    public static function setConnectionTags($predis, $span)
    {
        $hash = spl_object_hash($predis);
        if (!isset(self::$connections[$hash])) {
            return;
        }

        foreach (self::$connections[$hash] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }

    /**
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
