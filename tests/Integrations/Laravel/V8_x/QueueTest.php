<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Log\Logger;
use DDTrace\Tag;
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
            'DD_TRACE_DEBUG' => '1',
            'APP_NAME' => 'laravel_queue_test'
        ]);
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->resetQueue();
    }

    public function testSimplePushAndProcess()
    {
        $createTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create', '/queue/create');
            $this->call($spec);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work emails', '/queue/workOn');
            $this->call($spec);
        });

        // TODO: Check span links + Distributed tracing

        $this->assertFlameGraph($createTraces, [
            SpanAssertion::build(
                'laravel.request',
                'laravel_queue_test',
                'web',
                'App\Http\Controllers\QueueTestController@create unnamed_route'
            )->withExactTags([
                Tag::HTTP_URL               => 'http://localhost:9999/queue/create',
                Tag::HTTP_METHOD            => 'GET',
                'laravel.route.name'        => 'unnamed_route',
                'laravel.route.action'      => 'App\Http\Controllers\QueueTestController@create',
                Tag::SPAN_KIND              => 'server',
                Tag::COMPONENT              => 'laravel',
                Tag::HTTP_STATUS_CODE       => '200'
            ])->withChildren([
                SpanAssertion::build(
                    'laravel.action',
                    'laravel_queue_test',
                    'web',
                    'queue/create'
                )->withExactTags([
                    Tag::COMPONENT          => 'laravel'
                ])->withChildren([
                    $this->pushOneJob('App\Jobs\SendVerificationEmail -> emails', 'Illuminate\Queue\DatabaseQueue')
                ])
            ])],
            false
        );

        $this->assertFlameGraph($workTraces, [
            SpanAssertion::exists('laravel.artisan')
                ->withChildren([
                    SpanAssertion::exists('laravel.action')
                        ->withChildren([
                            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                        ])
                ])
        ], false
        );
    }

    public function testDispatchBatchAndProcess()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create batch', '/queue/batch');
            $this->call($spec);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work batch', '/queue/workOn');
            $this->call($spec);
        });

        $this->assertFlameGraph($workTraces, [
            SpanAssertion::exists('laravel.artisan')->withChildren([
                SpanAssertion::exists('laravel.action')->withChildren([
                    $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails'),
                    $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails'),
                ])
            ])
        ], false);
    }

    public function testDispatchBatchNowDefault()
    {
        $dispatchTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create batch', '/queue/batchDefault');
            $this->call($spec);
        });

        $this->assertFlameGraph($dispatchTraces, [
            SpanAssertion::exists('laravel.request')
                ->withChildren([
                    SpanAssertion::exists('laravel.action')
                        ->withChildren([
                            $this->spanQueuePush('Illuminate\Queue\SyncQueue')
                                ->withChildren([
                                    $this->spanEventJobProcessing(),
                                    $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                        ->withChildren([
                                            $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                            $this->spanQueueAction()
                                        ]),
                                    $this->spanEventJobProcessed()
                                ]),
                            $this->spanQueuePush('Illuminate\Queue\SyncQueue')
                                ->withChildren([
                                    $this->spanEventJobProcessing(),
                                    $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                        ->withChildren([
                                            $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                            $this->spanQueueAction()
                                        ]),
                                    $this->spanEventJobProcessed()
                                ])
                        ])
                ])
        ], false);
    }

    protected function resetQueue()
    {
        $this->connection()->exec("DELETE from jobs");
        $this->connection()->exec("DELETE from job_batches");
        $this->connection()->exec("DELETE from failed_jobs");
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    protected function spanEventJobProcessing()
    {
        return SpanAssertion::build(
            'laravel.event.handle',
            'laravel_queue_test',
            'web',
            'Illuminate\Queue\Events\JobProcessing'
        )->withExistingTagsNames([
            Tag::COMPONENT
        ]);
    }

    protected function spanEventJobProcessed()
    {
        return SpanAssertion::build(
            'laravel.event.handle',
            'laravel_queue_test',
            'web',
            'Illuminate\Queue\Events\JobProcessed'
        )->withExistingTagsNames([
            Tag::COMPONENT
        ]);
    }

    protected function getCommonTags(
        string $operation = 'process',
        $queue = 'emails',
        $connection = 'database'
    ) {
        $commonTags = [
            Tag::SPAN_KIND      => 'client',
            Tag::COMPONENT      => 'laravelqueue',

            Tag::MQ_SYSTEM      => 'laravel',

            'messaging.laravel.attempts'    => 1,
            'messaging.laravel.max_tries'   => 1,
            'messaging.laravel.timeout'     => 42,
            'messaging.laravel.name'        => 'App\Jobs\SendVerificationEmail'
        ];

        if ($operation) {
            $commonTags[Tag::MQ_OPERATION] = $operation;
        }

        if ($queue) {
            $commonTags['messaging.laravel.queue'] = $queue;
        }

        if ($connection) {
            $commonTags['messaging.laravel.connection'] = $connection;
        }

        return $commonTags;
    }

    protected function spanQueueAction()
    {
        return SpanAssertion::build(
            'laravel.queue.action',
            'laravelqueue',
            'queue',
            'App\Jobs\SendVerificationEmail@handle'
        )->withExactTags([
            Tag::COMPONENT  => 'laravelqueue'
        ]);
    }

    protected function spanQueueResolve(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        $span = SpanAssertion::build(
            'laravel.queue.resolve',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactTags(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
                'messaging.laravel.id'
            ]);
        }
    }

    protected function spanQueueFire(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        $span = SpanAssertion::build(
            'laravel.queue.fire',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactTags(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
                'messaging.laravel.id'
            ]);
        }
    }

    protected function spanQueueProcess(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        $span = SpanAssertion::build(
            'laravel.queue.process',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactTags(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
                'messaging.laravel.id'
            ]);
        }
    }

    protected function spanProcessOneJob(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        return $this->spanQueueProcess($connection, $queue, $resourceDetails)
            ->withChildren([
                $this->spanEventJobProcessing(),
                $this->spanQueueFire($connection, $queue, $resourceDetails)
                    ->withChildren([
                        $this->spanQueueResolve($connection, $queue, $resourceDetails),
                        $this->spanQueueAction()
                    ]),
                $this->spanEventJobProcessed()
            ]);
    }

    protected function spanQueuePush(string $resourceDetails = 'Illuminate\Queue\SyncQueue')
    {
        return SpanAssertion::build(
            'laravel.queue.push',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactTags([
            Tag::SPAN_KIND      => 'client',
            Tag::COMPONENT      => 'laravelqueue',
            Tag::MQ_OPERATION   => 'send',
        ]);
    }

    protected function spanQueueEnqueue(string $resourceDetails = 'App\Jobs\SendVerificationEmail')
    {
        return SpanAssertion::build(
            'laravel.queue.enqueueUsing',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactTags([
            Tag::SPAN_KIND      => 'client',
            Tag::COMPONENT      => 'laravelqueue',
        ]);
    }

    protected function pushOneJob(
        string $resourceDetails = 'App\Jobs\SendVerificationEmail',
        string $queueClass = 'Illuminate\Queue\SyncQueue'
    ) {
        return $this->spanQueuePush($queueClass)
            ->withChildren([
                $this->spanQueueEnqueue($resourceDetails)
            ]);
    }
}
