<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;
use Predis\Client;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class Predis
{
    /**
     * Static method to add instrumentation to the Predis library
     */
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Predis integration.', E_USER_WARNING);
            return;
        }

        // public mixed Predis\Client::executeCommand(CommandInterface $command)
        dd_trace(Client::class, 'executeCommand', function ($command) {
            $query = Predis::formatCommandArgs($command);
            $scope = GlobalTracer::get()->startActiveSpan('Predis.executeCommand');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($command->getArguments()));
            $span->setResource($query);

            $e = null;
            try {
                $result = $this->executeCommand($command);
            } catch (\Exception $e) {
                $span->setError($e);
            }

            $scope->close();

            if ($e === null) {
                return $result;
            } else {
                throw $e;
            }
        });
    }

    /* things to include (from python tracer):
# net extension
DB = 'out.redis_db'

# standard tags
RAWCMD = 'redis.raw_command'
ARGS_LEN = 'redis.args_length'
PIPELINE_LEN = 'redis.pipeline_length'
PIPELINE_AGE = 'redis.pipeline_age'
IMMEDIATE_PIPELINE = 'redis.pipeline_immediate_command'

def _extract_conn_tags(conn_kwargs):
    """ Transform redis conn info into dogtrace metas """
    try:
        return {
            net.TARGET_HOST: conn_kwargs['host'],
            net.TARGET_PORT: conn_kwargs['port'],
            redisx.DB: conn_kwargs['db'] or 0,
        }
    except Exception:
        return {}
    */

    /**
     * Format a command by removing unwanted values
     *
     * Restrict what we keep from the values sent (with a SET, HGET, LPUSH, ...):
     * - Skip binary content
     * - Truncate
     */
    public static function formatCommandArgs($command)
    {
        $arguments = $command->getArguments();
        array_unshift($arguments, $command->getId());

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

    public static function extractConnTags($predisClient)
    {
        /*
        net.TARGET_HOST: conn_kwargs['host'],
        net.TARGET_PORT: conn_kwargs['port'],
        redisx.DB: conn_kwargs['db'] or 0,
        */
    }
}
