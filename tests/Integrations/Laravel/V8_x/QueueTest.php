<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanChecker;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class QueueTest extends WebFrameworkTestCase
{
    public static $database = "laravel8";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_CLI_ENABLED' => '1',
            'APP_NAME' => 'laravel_queue_test',
            'DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE' => '0',
            'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
        ]);
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->resetQueue();
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS'
        ];
    }

    public function testBroadcast()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS=1']);
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Broadcast', '/queue/broadcast');
            $this->call($spec);
            sleep(3);
        });
    }

    public function testSimplePushAndProcess()
    {
        $createTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create', '/queue/create');
            $this->call($spec);
            sleep(3);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work emails', '/queue/workOn');
            $this->call($spec);
            sleep(4);
        });

        $this->assertFlameGraph(
            $createTraces,
            [
                SpanAssertion::exists('laravel.request')
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withExactAttributes([
                                Tag::COMPONENT => 'laravel',
                                '_dd.svc_src' => 'laravel',
                            ])->withChildren([
                                $this->spanQueuePush('database', 'emails', 'Illuminate\Queue\DatabaseQueue')
                                    ->withChildren([
                                        $this->spanQueueEnqueue('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                    ])
                            ])
                    ])
            ],
            false
        );

        // $workTraces should have 2 traces: 1 'laravel.queue.process' and 1 'laravel.artisan'
        $processTrace1 = [$workTraces[0]];
        $artisanTrace = [$workTraces[1]];


        $this->assertFlameGraph($processTrace1, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
        ], false);

        $this->assertFlameGraph($artisanTrace, [
            SpanAssertion::exists('laravel.artisan')
                ->withChildren([
                    SpanAssertion::exists('laravel.action')
                        ->withChildren([
                            $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                        ])
                ])
        ], false);

        $processSpanFromArtisanTrace = array_filter($artisanTrace[0]['spans'], function ($span) {
            return $span['name'] === 'laravel.queue.process';
        });
        $processSpanFromArtisanTrace = array_values($processSpanFromArtisanTrace)[0];

        // The parsed traces carry span links with hex ids.
        $spanLinksTraceId = $processSpanFromArtisanTrace['span_links'][0]['trace_id'];
        $spanLinksSpanId = $processSpanFromArtisanTrace['span_links'][0]['span_id'];

        $processSpanFromProcessTrace = array_filter($processTrace1[0]['spans'], function ($span) {
            return $span['name'] === 'laravel.queue.process';
        });
        $processSpanFromProcessTrace = array_values($processSpanFromProcessTrace)[0];
        $processTraceId = $processTrace1[0]['trace_id'];
        $processSpanId = $processSpanFromProcessTrace['span_id'];
        $processParentId = $processSpanFromProcessTrace['parent_id'];

        $hexProcessSpanId = SpanChecker::decimalToHex($processSpanId, 16);

        $this->assertSame($spanLinksTraceId, $processTraceId);
        $this->assertSame($spanLinksSpanId, $hexProcessSpanId);

        $pushSpanFromCreateTrace = array_filter($createTraces[0]['spans'], function ($span) {
            return $span['name'] === 'laravel.queue.push';
        });
        $pushSpanFromCreateTrace = array_values($pushSpanFromCreateTrace)[0];

        $this->assertSame($createTraces[0]['trace_id'], $processTraceId);
        $this->assertSame($pushSpanFromCreateTrace['span_id'], $processParentId);
    }

    public function testJobFailure()
    {
        $createTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create', '/queue/jobFailure');
            $this->call($spec);
            sleep(3);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work emails', '/queue/workOn');
            $this->call($spec);
            sleep(4);
        });

        $this->assertFlameGraph(
            $createTraces,
            [
                SpanAssertion::exists('laravel.request')
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withExactAttributes([
                                Tag::COMPONENT => 'laravel',
                                '_dd.svc_src' => 'laravel',
                            ])->withChildren([
                                $this->spanQueueLater('database', 'emails', 'Illuminate\Queue\DatabaseQueue')
                                    ->withChildren([
                                        $this->spanQueueEnqueue('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                    ])
                            ])
                    ])
            ],
            false
        );

        // $workTraces should have 2 traces: 1 'laravel.queue.process' and 1 'laravel.artisan'
        $processTrace1 = [$workTraces[0]];
        $artisanTrace = [$workTraces[1]];

        $this->assertFlameGraph(
            $processTrace1,
            [
                $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                    ->setError('Exception', 'Triggered Exception', true)
                    ->withChildren([
                        $this->spanQueueResolve('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails'),
                        $this->spanQueueFire('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                            ->setError('Exception', 'Triggered Exception', true)
                            ->withChildren([
                                $this->spanQueueAction('database', 'emails')
                                    ->withExistingAttributeNames([Tag::MQ_MESSAGE_ID])
                                    ->setError('Exception', 'Triggered Exception', true)
                            ])
                    ])
            ],
            false
        );

        $this->assertFlameGraph(
            $artisanTrace,
            [
                SpanAssertion::exists('laravel.artisan')
                    ->setError('Exception', 'Triggered Exception', true)
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withChildren([
                                $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                    ->setError('Exception', 'Triggered Exception', true)
                            ])
                    ])
            ],
            false
        );
    }

    public function testDispatchBatchAndProcess()
    {
        $createTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create batch', '/queue/batch');
            $this->call($spec);
            sleep(3);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work batch', '/queue/workOn');
            $this->call($spec);
            sleep(7);
        });

        // $workTraces should have 2 traces: One with 2 'laravel.queue.process' and the other with 1 'laravel.artisan'
        usort($workTraces, function ($a, $b) {
            return $a['spans'][0]['start'] - $b['spans'][0]['start'];
        });
        $artisanTrace = [$workTraces[0]];
        $processTrace1 = [$workTraces[1]];
        $processTrace2 = [$workTraces[2]];

        $this->assertFlameGraph($processTrace1, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails', true)
        ], false);

        $this->assertFlameGraph($processTrace2, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails', true)
        ], false);


        $this->assertFlameGraph($artisanTrace, [
            SpanAssertion::exists('laravel.artisan')->withChildren([
                SpanAssertion::exists('laravel.action')->withChildren([
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails'),
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails'),
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                ])
            ])
        ], false);
    }

    public function testDispatchBatchNowDefault()
    {
        $dispatchTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue create batch', '/queue/batchDefault');
            $this->call($spec);
            sleep(3);
        });

        $this->assertFlameGraph($dispatchTraces, [
            SpanAssertion::exists('laravel.request')
                ->withChildren([
                    SpanAssertion::exists('laravel.action')
                        ->withChildren([
                            $this->spanQueueBatchAdd()
                                ->withChildren([
                                    $this->spanQueuePush('sync', 'default', 'Illuminate\Queue\SyncQueue')
                                        ->withExistingAttributeNames([Tag::LARAVELQ_BATCH_ID])
                                        ->withChildren([
                                            $this->spanEventJobProcessing(),
                                            $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                                ->withChildren([
                                                    $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                                    $this->spanQueueAction('sync', 'sync')
                                                        ->withExistingAttributeNames([Tag::LARAVELQ_BATCH_ID])
                                                ]),
                                            $this->spanEventJobProcessed()
                                        ]),
                                    $this->spanQueuePush('sync', 'default', 'Illuminate\Queue\SyncQueue')
                                        ->withExistingAttributeNames([Tag::LARAVELQ_BATCH_ID])
                                        ->withChildren([
                                            $this->spanEventJobProcessing(),
                                            $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                                ->withChildren([
                                                    $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                                    $this->spanQueueAction('sync', 'sync')
                                                        ->withExistingAttributeNames([Tag::LARAVELQ_BATCH_ID])
                                                ]),
                                            $this->spanEventJobProcessed()
                                        ])
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
        return new \PDO('mysql:host=mysql-integration;dbname=laravel8', 'test', 'test');
    }

    protected function spanEventJobProcessing()
    {
        return SpanAssertion::build(
            'laravel.event.handle',
            'laravel_queue_test',
            'web',
            'Illuminate\Queue\Events\JobProcessing'
        )->withExistingAttributeNames([
            Tag::COMPONENT,
            '_dd.svc_src',
        ]);
    }

    protected function spanEventJobProcessed()
    {
        return SpanAssertion::build(
            'laravel.event.handle',
            'laravel_queue_test',
            'web',
            'Illuminate\Queue\Events\JobProcessed'
        )->withExistingAttributeNames([
            Tag::COMPONENT,
            '_dd.svc_src',
        ]);
    }

    protected function getCommonTags(
        $operation = 'process',
        $queue = 'emails',
        $connection = 'database'
    ) {
        $commonTags = [
            Tag::SPAN_KIND                  => 'client',
            Tag::COMPONENT                  => 'laravelqueue',

            '_dd.svc_src' => 'laravelqueue',
            Tag::MQ_SYSTEM                  => 'laravel',
            Tag::MQ_DESTINATION_KIND        => 'queue',

            Tag::LARAVELQ_ATTEMPTS          => 1,
            Tag::LARAVELQ_MAX_TRIES         => 1,
            Tag::LARAVELQ_TIMEOUT           => 42,
            Tag::LARAVELQ_NAME              => 'App\Jobs\SendVerificationEmail'
        ];

        if ($operation) {
            $commonTags[Tag::MQ_OPERATION] = $operation;
        }

        if ($queue) {
            $commonTags[Tag::MQ_DESTINATION] = $queue;
        }

        if ($connection) {
            $commonTags[Tag::LARAVELQ_CONNECTION] = $connection;
        }

        return $commonTags;
    }

    protected function spanQueueAction(
        $connection = 'database',
        $queue = 'emails'
    ) {
        return SpanAssertion::build(
            'laravel.queue.action',
            'laravel_queue_test',
            'queue',
            'App\Jobs\SendVerificationEmail@handle'
        )->withExactAttributes(
            $this->getCommonTags(null, $queue, $connection)
        );
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
        )->withExactAttributes(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingAttributeNames([
                Tag::MQ_MESSAGE_ID
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
        )->withExactAttributes(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingAttributeNames([
                Tag::MQ_MESSAGE_ID
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
        )->withExactAttributes(
            $this->getCommonTags('receive', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingAttributeNames([
                Tag::MQ_MESSAGE_ID,
            ])->withSpanLinks();
        }
    }

    protected function spanProcessOneJob(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail',
        bool $isFromBatch = false
    ) {
        return SpanAssertion::build(
            'laravel.queue.process',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactAttributes(
            $this->getCommonTags('receive', $queue, $connection)
        )->withExistingAttributeNames([
            Tag::MQ_MESSAGE_ID,
        ])->withSpanLinks()->withChildren([
            $this->spanEventJobProcessing(),
            $this->spanQueueFire($connection, $queue, $resourceDetails)
                ->withChildren([
                    $this->spanQueueResolve($connection, $queue, $resourceDetails),
                    ($isFromBatch
                        ? $this->spanQueueAction($connection, $queue, $resourceDetails)
                            ->withExistingAttributeNames([
                                Tag::LARAVELQ_BATCH_ID,
                                Tag::MQ_MESSAGE_ID
                            ])
                        : $this->spanQueueAction($connection, $queue, $resourceDetails)
                            ->withExistingAttributeNames([
                                Tag::MQ_MESSAGE_ID
                            ])
                    )
                ]),
            $this->spanEventJobProcessed()
        ]);
    }

    protected function spanQueuePush(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        return SpanAssertion::build(
            'laravel.queue.push',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactAttributes(
            $this->getCommonTags('send', $queue, $connection)
        );
    }

    protected function spanQueueLater(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        return SpanAssertion::build(
            'laravel.queue.later',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactAttributes(
            $this->getCommonTags('send', $queue, $connection)
        );
    }

    protected function spanQueueEnqueue(
        $connection = 'database',
        $queue = 'emails',
        $resourceDetails = 'App\Jobs\SendVerificationEmail'
    ) {
        return SpanAssertion::build(
            'laravel.queue.enqueueUsing',
            'laravel_queue_test',
            'queue',
            $resourceDetails
        )->withExactAttributes(
            $this->getCommonTags(null, $queue, $connection)
        );
    }

    protected function spanQueueBatchAdd()
    {
        return SpanAssertion::build(
            'laravel.queue.batch.add',
            'laravel_queue_test',
            'queue',
            'Illuminate\Bus\Batch'
        )->withExactAttributes([
            Tag::SPAN_KIND      => 'client',
            Tag::COMPONENT      => 'laravelqueue',
            '_dd.svc_src' => 'laravelqueue',
            Tag::MQ_OPERATION   => 'send'
        ])->withExistingAttributeNames([
            Tag::LARAVELQ_BATCH_ID
        ]);
    }
}
