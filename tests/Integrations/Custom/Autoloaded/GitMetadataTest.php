<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

// IMPORTANT: The order of tests MATTERS (but should all succeed even ran individually)
// This is because caching is global
// First, an invalid .git folder exists
// Then, this invalid .git folder is replaced with a valid one
// Then, this valid .git folder doesn't exists anymore and the base .git folder is used (dd-trace-php)
// Then, the valid .git folder is created again
// Then, the commit sha of the latter is changed

final class GitMetadataTest extends WebFrameworkTestCase
{
    const GIT_DIR = __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/.git';

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        system("rm -rf " . self::GIT_DIR);
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        system("rm -rf " . self::GIT_DIR);
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_GIT_METADATA_ENABLED' => 'true',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    public function mkdirGitDir($gitDir) {
        if (!file_exists($gitDir)) {
            mkdir($gitDir);
        }
    }

    public function generateHEAD($gitDir) {
        $headFile = $gitDir . '/HEAD';
        if (!file_exists($headFile)) {
            file_put_contents($headFile, 'ref: refs/heads/master');
        }
    }

    public function mkdirHeadsFolder($gitDir) {
        $headsDir = $gitDir . '/refs/heads';
        if (!file_exists($headsDir)) {
            mkdir($headsDir, 0777, true);
        }

        $masterFile = $headsDir . '/master';
        if (!file_exists($masterFile)) {
            file_put_contents($masterFile, '123456');
        }
    }

    public function generateConfigFile($gitDir) {
        $configFile = $gitDir . '/config';
        if (!file_exists($configFile)) {
            file_put_contents($configFile, <<<CONFIG
[core]
    repositoryformatversion = 0
    filemode = true
    bare = false
    logallrefupdates = true
    ignorecase = true
    precomposeunicode = true
[remote "origin"]
    url = https://u:t@github.com/user/repo_new
    fetch = +refs/heads/*:refs/remotes/origin/*
[branch "main"]
    remote = origin
    merge = refs/heads/main
CONFIG
            );
        }
    }

    public function generateFullFakeGitFolder() {
        $this->mkdirGitDir(self::GIT_DIR);
        $this->generateHEAD(self::GIT_DIR);
        $this->mkdirHeadsFolder(self::GIT_DIR);
        $this->generateConfigFile(self::GIT_DIR);
    }

    public function generateInvalidFakeGitFolder() {
        $this->mkdirGitDir(self::GIT_DIR);
        //generateHEAD($gitDir);
        $this->mkdirHeadsFolder(self::GIT_DIR);
        $this->generateConfigFile(self::GIT_DIR);
    }

    public function changeRefsHeadsMaster($commitSha) {
        $headFile = self::GIT_DIR . '/refs/heads/master';
        file_put_contents($headFile, $commitSha);
    }

    public function testSourceCodeIntegrationInvalidThenValid()
    {
        $this->generateInvalidFakeGitFolder();

        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/simple');
            $this->call($spec);
        });
        $payload1Attributes = $this->dumpedPayload['attributes'] ?? [];

        system("rm -rf " . self::GIT_DIR);
        $this->generateFullFakeGitFolder();

        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/simple');
            $this->call($spec);
        });
        $payload2Attributes = $this->dumpedPayload['attributes'];

        $this->assertArrayNotHasKey('_dd.git.commit.sha', $payload1Attributes);
        $this->assertEquals('https://github.com/user/repo_new', $payload1Attributes['_dd.git.repository_url']);

        $this->assertEquals('123456', $payload2Attributes['_dd.git.commit.sha']);
        $this->assertEquals('https://github.com/user/repo_new', $payload2Attributes['_dd.git.repository_url']);
    }

    public function testSourceCodeIntegration()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/simple');
            $this->call($spec);
        });

        $payloadAttributes = $this->dumpedPayload['attributes'];

        $gitCommitSha = trim(shell_exec('git rev-parse HEAD'));
        $gitRepositoryURL = preg_replace('((?<=//)[^@]+@)', '', trim(shell_exec('git config --get remote.origin.url')));

        $this->assertEquals($gitCommitSha, $payloadAttributes['_dd.git.commit.sha']);
        $this->assertEquals($gitRepositoryURL, $payloadAttributes['_dd.git.repository_url']);
    }

    public function testSourceCodeIntegrationChangeCommitSha()
    {
        $this->generateFullFakeGitFolder();

        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/simple');
            $this->call($spec);
        });
        $payload1Attributes = $this->dumpedPayload['attributes'];

        $this->changeRefsHeadsMaster('abc123def456');

        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Source Code Integration test', '/simple');
            $this->call($spec);
        });
        $payload2Attributes = $this->dumpedPayload['attributes'];

        $this->assertEquals('123456', $payload1Attributes['_dd.git.commit.sha']);
        $this->assertEquals('abc123def456', $payload2Attributes['_dd.git.commit.sha']);
    }
}
