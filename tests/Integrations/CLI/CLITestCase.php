<?php

namespace DDTrace\Tests\Integrations\CLI;

use DDTrace\Tests\Common\AgentReplayerTrait;
use DDTrace\Tests\Common\IntegrationTestCase;

/**
 * A basic class to be extended when testing CLI integrations.
 */
abstract class CLITestCase extends IntegrationTestCase
{
    use AgentReplayerTrait;

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
        return [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_AGENT_HOST' => 'request-replayer',
            'DD_TRACE_AGENT_PORT' => '80',
            // Uncomment to see debug-level messages
            //'DD_TRACE_DEBUG' => 'true',
            'DD_TEST_INTEGRATION' => 'true',
            'DD_TRACE_ENCODER' => 'json',
        ];
    }

    /**
     * Get additional INI directives to be set in the CLI
     *
     * @return array
     */
    protected static function getInis()
    {
        return [
            'ddtrace.request_init_hook' => __DIR__ . '/../../../bridge/dd_wrap_autoloader.php',
            // Enabling `strict_mode` disables debug mode
            //'ddtrace.strict_mode' => '1',
        ];
    }

    /**
     * Run a command from the CLI
     *
     * @param string $arguments
     * @return array
     */
    public function getTracesFromCommand($arguments = '')
    {
        $envs = (string) new EnvSerializer(static::getEnvs());
        $inis = (string) new IniSerializer(static::getInis());
        $script = escapeshellarg($this->getScriptLocation());
        $arguments = escapeshellarg($arguments);
        `$envs php $inis $script $arguments`;
        return $this->loadTrace();
    }

    /**
     * Load the last trace that was sent to the dummy agent
     *
     * @return array
     */
    private function loadTrace()
    {
        $request = $this->getLastAgentRequest();
        if (!isset($request['body'])) {
            return [];
        }
        return json_decode($request['body'], true);
    }
}
