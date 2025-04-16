<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class AgentUrlEnvVarInvalidTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AGENT_URL' => 'http://invalid_hostname:1337',
        ]);
    }

    public function testInvalidAgentUrlEnvVarTakesPrecedence()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Invalid DD_TRACE_AGENT_URL takes precedence', '/simple');
            $this->call($spec);
        });

        self::assertEmpty($traces);
    }
}
