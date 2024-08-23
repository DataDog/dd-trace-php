<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tests\Common\AgentReplayerTrait;
use DDTrace\Tests\Common\IntegrationTestCase;

/**
 * A basic class to be extended when testing CLI integrations.
 */
abstract class CLITestCase extends IntegrationTestCase
{
    /**
     * The location of the script to execute
     *
     * @return string
     */
    abstract protected function getScriptLocation();

    /**
     * Get additional envs
     *
     * @return array
     */
    protected static function getEnvs()
    {
        $envs = [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_AGENT_HOST' => 'test-agent',
            'DD_TRACE_AGENT_PORT' => '9126',
            // Uncomment to see debug-level messages
            'DD_TRACE_DEBUG' => 'true',
            'DD_TEST_INTEGRATION' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_TRACE_SHUTDOWN_TIMEOUT' => '666666', // Arbitrarily high value to avoid flakiness
            'DD_TRACE_AGENT_RETRIES' => '3',
            'DD_TRACE_AGENT_TEST_SESSION_TOKEN' => ini_get("datadog.trace.agent_test_session_token"),
        ];
        return $envs;
    }

    /**
     * Get additional INI directives to be set in the CLI
     *
     * @return array
     */
    protected static function getInis()
    {
        return [
            'datadog.trace.sources_path' => __DIR__ . '/../../src',
            // Enabling `strict_mode` disables debug mode
            //'ddtrace.strict_mode' => '1',
        ];
    }

    /**
     * Run a command from the CLI and return the generated traces.
     *
     * @param string $arguments
     * @param array $overrideEnvs
     * @return array
     */
    public function getTracesFromCommand($arguments = '', $overrideEnvs = [])
    {
        return $this->loadTraces($this->getAgentRequestFromCommand($arguments, $overrideEnvs));
    }

    /**
     * Run a command from the CLI and return the raw response.
     *
     * @param string $arguments
     * @param array $overrideEnvs
     * @return array | null
     */
    public function getAgentRequestFromCommand($arguments = '', $overrideEnvs = [])
    {
        $this->executeCommand($arguments, $overrideEnvs);
        return $this->retrieveDumpedTraceData()[0] ?? [];
    }

    /**
     * Run a command from the CLI.
     *
     * @param string $arguments
     * @param array $overrideEnvs
     */
    public function executeCommand($arguments = '', $overrideEnvs = [])
    {
        $envs = (string) new EnvSerializer(array_merge([], static::getEnvs(), $overrideEnvs));
        $inis = (string) new IniSerializer(static::getInis());
        $script = escapeshellarg($this->getScriptLocation());
        $arguments = escapeshellarg($arguments);
        $commandToExecute = "$envs " . PHP_BINARY . " $inis $script $arguments";
        `$commandToExecute`;
        if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        }
    }

    /**
     * Load the last trace that was sent to the dummy agent
     *
     * @return array
     */
    public function loadTraces($request)
    {
        if (!isset($request['body'])) {
            return [];
        }
        $traces = json_decode($request['body'], true);
        if (isset($traces['chunks'])) {
            $traces = array_map(function($chunk) { return $chunk["spans"]; }, $traces["chunks"]);
        }
        return $traces;
    }
}
