<?php

namespace DDTrace\Tests\Integrations\CLI;

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
        return [
            'DD_TRACE_CLI_ENABLED' => 'true',
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
            'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
        ];
    }

    /**
     * Run a command from the CLI
     *
     * @param string $arguments
     * @return string
     */
    public function runCommand($arguments = '')
    {
        $envs = (string) new EnvSerializer(self::getEnvs());
        $inis = (string) new IniSerializer(self::getInis());
        $script = escapeshellarg($this->getScriptLocation());
        $arguments = escapeshellarg($arguments);
        return `$envs php $inis $script $arguments`;
    }
}
