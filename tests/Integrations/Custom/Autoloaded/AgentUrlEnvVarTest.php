<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class AgentUrlEnvVarTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AGENT_URL' => 'http://test-agent:9126',
            'DD_AGENT_HOST' => 'invalid_hostname',
            'DD_TRACE_AGENT_PORT' => 1337,
        ]);
    }

    public function testAgentUrlEnvVarTakesPrecedence()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('DD_TRACE_AGENT_URL takes precedence', '/simple');
            $this->call($spec);
        });

        self::assertSame('GET /simple', $traces[0][0]['resource']);
    }
}
