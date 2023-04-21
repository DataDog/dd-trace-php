<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Log\Logger;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\SpanChecker;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class QueueTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_CLI_ENABLED' => '1',
            'DD_TRACE_DEBUG' => '1'
        ]);
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        //$this->resetQueue();
    }

    public function testCreate()
    {
        fwrite(STDERR, "Start\n");
        $createTraces = $this->tracesFromWebRequest(function () {
            file_put_contents('laravelQueueDebug.txt', 'Start' . PHP_EOL, FILE_APPEND);
            $spec = GetSpec::create('Queue create', '/queue/create');
            file_put_contents('laravelQueueDebug.txt', 'Created' . PHP_EOL, FILE_APPEND);
            $this->call($spec);
            file_put_contents('laravelQueueDebug.txt', 'Called' . PHP_EOL, FILE_APPEND);
        });
        fwrite(STDERR, "Request made\n");

        // Do artisan queue:work --once
        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work emails', '/queue/workOn');
            $this->call($spec);
        });
        fwrite(STDERR, "Work made\n");

        //fwrite(STDERR, print_r($traces, TRUE));
        $spanChecker = new SpanChecker();
        $flattenTraces = $spanChecker->flattenTraces($createTraces);
        $actualGraph = $spanChecker->buildSpansGraph($flattenTraces);
        fwrite(STDERR, SpanChecker::dumpSpansGraph($actualGraph));

        $flattenTraces = $spanChecker->flattenTraces($workTraces);
        $actualGraph = $spanChecker->buildSpansGraph($flattenTraces);
        fwrite(STDERR, SpanChecker::dumpSpansGraph($actualGraph));
    }

    protected function resetQueue()
    {
        $this->connection()->exec("DELETE from jobs");
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
