<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class GitMetadataTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_GIT_METADATA_ENABLED' => 'true',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    public function testSourceCodeIntegration()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/pdo');
            $this->call($spec);
        });

        $rootSpanMeta = $traces[0][0]['meta'];
        $pdoSpanMeta = $traces[0][1]['meta'];

        $gitCommitSha = trim(`git rev-parse HEAD`);
        $gitRepositoryURL = trim(`git config --get remote.origin.url`);

        $this->assertEquals($gitCommitSha, $rootSpanMeta['_dd.git.commit.sha']);
        $this->assertEquals($gitRepositoryURL, $rootSpanMeta['_dd.git.repository_url']);

        $this->assertEquals($gitCommitSha, $pdoSpanMeta['git.commit.sha']);
        $this->assertEquals($gitRepositoryURL, $pdoSpanMeta['git.repository_url']);
    }
}
