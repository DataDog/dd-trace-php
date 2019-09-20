<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\GlobalTracer;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\AbstractConnection;
use Predis\Pipeline\Pipeline;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class PredisIntegration extends Integration
{
    const NAME = 'predis';

    /**
     * @var array
     */
    private static $connections = [];

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Static method to add instrumentation to the Predis library
     */
    public static function load()
    {
        // public Predis\Client::__construct ([ mixed $dsn [, mixed $options ]] )
        dd_trace('Predis\Client', '__construct', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                PredisIntegration::getInstance(),
                'Predis.Client.__construct'
            );
            $span = $scope->getSpan();

            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag(Tag::RESOURCE_NAME, 'Predis.Client.__construct');

            $thrown = null;
            try {
                dd_trace_forward_call();
                PredisIntegration::storeConnectionParams($this, func_get_args());
                PredisIntegration::setConnectionTags($this, $span);
            } catch (\Exception $e) {
                $thrown = $e;
                $span->setError($e);
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $this;
        });

        // public void Predis\Client::connect()
        dd_trace('Predis\Client', 'connect', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                PredisIntegration::getInstance(),
                'Predis.Client.connect'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag(Tag::RESOURCE_NAME, 'Predis.Client.connect');
            PredisIntegration::setConnectionTags($this, $span);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // public mixed Predis\Client::executeCommand(CommandInterface $command)
        dd_trace('Predis\Client', 'executeCommand', function ($command) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $query = PredisIntegration::formatArguments($arguments);

            $scope = $tracer->startIntegrationScopeAndSpan(
                PredisIntegration::getInstance(),
                'Predis.Client.executeCommand'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($arguments));
            $span->setTag(Tag::RESOURCE_NAME, $query);
            $span->setTraceAnalyticsCandidate();
            PredisIntegration::setConnectionTags($this, $span);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // public mixed Predis\Client::executeRaw(array $arguments, bool &$error)
        dd_trace('Predis\Client', 'executeRaw', function ($arguments, &$error = null) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $query = PredisIntegration::formatArguments($arguments);

            $scope = $tracer->startIntegrationScopeAndSpan(
                PredisIntegration::getInstance(),
                'Predis.Client.executeRaw'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($arguments));
            $span->setTag(Tag::RESOURCE_NAME, $query);
            $span->setTraceAnalyticsCandidate();
            PredisIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally block.
            // Note that we do not use the TryCatchFinally helper class because $error is a reference here which
            // causes problems with call_user_func_array, used internally.
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
            } catch (\Exception $ex) {
                $thrown = $ex;
                $span->setError($ex);
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        // protected array Predis\Pipeline::executePipeline(ConnectionInterface $connection, \SplQueue $commands)
        dd_trace('Predis\Pipeline\Pipeline', 'executePipeline', function ($connection, $commands) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                PredisIntegration::getInstance(),
                'Predis.Pipeline.executePipeline'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag('redis.pipeline_length', count($commands));
            PredisIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally block.
            // Note that we are not using the TryCatchFinally::executePublicMethod because this method
            // is protected.
            $thrown = null;
            $result = null;
            $span = $scope->getSpan();
            try {
                $result = dd_trace_forward_call();
            } catch (\Exception $ex) {
                $thrown = $ex;
                $span->setError($ex);
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        return Integration::LOADED;
    }

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
