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
use Illuminate\Support\Facades\Artisan;

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
            sleep(3);
        });

        $workTraces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Queue work emails', '/queue/workOn');
            $this->call($spec);
            sleep(3);
        });

        $this->assertFlameGraph(
            $createTraces,
            [
                SpanAssertion::exists('laravel.request')
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withExactTags([
                                Tag::COMPONENT => 'laravel'
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
        $processTrace1 = $workTraces[0];
        $artisanTrace = $workTraces[1];


        $this->assertFlameGraph($processTrace1, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
        ], false);

        $this->assertFlameGraph($artisanTrace, [
            SpanAssertion::exists('laravel.artisan')
                ->withChildren([
                    SpanAssertion::exists('laravel.action')
                        ->withChildren([
                            $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                ->withExistingTagsNames(['_dd.span_links'])
                        ])
                ])
        ], false);

        $processSpanFromArtisanTrace = array_filter($artisanTrace[0], function ($span) {
            return $span['name'] === 'laravel.queue.process';
        });
        $processSpanFromArtisanTrace = array_values($processSpanFromArtisanTrace)[0];

        $spanLinks = $processSpanFromArtisanTrace['meta']['_dd.span_links'];
        $spanLinksTraceId = hexdec(json_decode($spanLinks, true)[0]['trace_id']);
        $spanLinksSpanId = hexdec(json_decode($spanLinks, true)[0]['span_id']);

        $processSpanFromProcessTrace = array_filter($processTrace1[0], function ($span) {
            return $span['name'] === 'laravel.queue.process';
        });
        $processSpanFromProcessTrace = array_values($processSpanFromProcessTrace)[0];
        $processTraceId = $processSpanFromProcessTrace['trace_id'];
        $processSpanId = $processSpanFromProcessTrace['span_id'];
        $processParentId = $processSpanFromProcessTrace['parent_id'];

        $this->assertTrue($spanLinksTraceId == $processTraceId);
        $this->assertTrue($spanLinksSpanId == $processSpanId);

        $pushSpanFromCreateTrace = array_filter($createTraces[0], function ($span) {
            return $span['name'] === 'laravel.queue.push';
        });
        $pushSpanFromCreateTrace = array_values($pushSpanFromCreateTrace)[0];

        $this->assertSame($pushSpanFromCreateTrace['trace_id'], $processTraceId);
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
            $spec = GetSpec::create('Queue work emails', '/queue/workEmails');
            $this->call($spec);
            sleep(3);
        });

        $this->assertFlameGraph(
            $createTraces,
            [
                SpanAssertion::exists('laravel.request')
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withExactTags([
                                Tag::COMPONENT => 'laravel'
                            ])->withChildren([
                                $this->spanQueuePush('database', 'emails', 'Illuminate\Queue\SyncQueue')
                                    ->withChildren([
                                        $this->spanQueueEnqueue('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                    ])
                            ])
                    ])
            ],
            false
        );

        // $workTraces should have 2 traces: 1 'laravel.queue.process' and 1 'laravel.artisan'
        $processTrace1 = $workTraces[0];
        $artisanTrace = $workTraces[1];

        $this->assertFlameGraph(
            $processTrace1,
            [
                $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                    ->withExistingTagsNames(['error.msg', 'error.stack'])
            ],
            false
        );

        $this->assertFlameGraph(
            $artisanTrace,
            [
                SpanAssertion::exists('laravel.artisan')
                    ->withChildren([
                        SpanAssertion::exists('laravel.action')
                            ->withChildren([
                                $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                                    ->withExistingTagsNames(['_dd.span_links'])
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
            sleep(3);
        });

        // $workTraces should have 2 traces: One with 2 'laravel.queue.process' and the other with 1 'laravel.artisan'
        $processTrace1 = [$workTraces[0][0]];
        $processTrace2 = [$workTraces[0][1]];
        $artisanTrace = $workTraces[1];

        $this->assertFlameGraph($processTrace1, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails', true)
        ], false);

        $this->assertFlameGraph($processTrace2, [
            $this->spanProcessOneJob('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails', true)
        ], false);


        $this->assertFlameGraph($artisanTrace, [
            SpanAssertion::exists('laravel.artisan')->withChildren([
                SpanAssertion::exists('laravel.action')->withChildren([
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                        ->withExistingTagsNames(['_dd.span_links']),
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                        ->withExistingTagsNames(['_dd.span_links']),
                    $this->spanQueueProcess('database', 'emails', 'App\Jobs\SendVerificationEmail -> emails')
                        ->withExistingTagsNames(['_dd.span_links']),
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
                                        ->withExistingTagsNames(['messaging.laravel.batch_id'])
                                        ->withChildren([
                                            $this->spanEventJobProcessing(),
                                            $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                                ->withChildren([
                                                    $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                                    $this->spanQueueAction('sync', 'sync')
                                                        ->withExistingTagsNames(['messaging.laravel.batch_id'])
                                                ]),
                                            $this->spanEventJobProcessed()
                                        ]),
                                    $this->spanQueuePush('sync', 'default', 'Illuminate\Queue\SyncQueue')
                                        ->withExistingTagsNames(['messaging.laravel.batch_id'])
                                        ->withChildren([
                                            $this->spanEventJobProcessing(),
                                            $this->spanQueueFire('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync')
                                                ->withChildren([
                                                    $this->spanQueueResolve('sync', 'sync', 'App\Jobs\SendVerificationEmail -> sync'),
                                                    $this->spanQueueAction('sync', 'sync')
                                                        ->withExistingTagsNames(['messaging.laravel.batch_id'])
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
        $operation = 'process',
        $queue = 'emails',
        $connection = 'database'
    ) {
        $commonTags = [
            Tag::SPAN_KIND                  => 'client',
            Tag::COMPONENT                  => 'laravelqueue',

            Tag::MQ_SYSTEM                  => 'laravel',
            Tag::MQ_DESTINATION_KIND        => 'queue',

            'messaging.laravel.attempts'    => 1,
            'messaging.laravel.max_tries'   => 1,
            'messaging.laravel.timeout'     => 42,
            'messaging.laravel.name'        => 'App\Jobs\SendVerificationEmail'
        ];

        if ($operation) {
            $commonTags[Tag::MQ_OPERATION] = $operation;
        }

        if ($queue) {
            $commonTags[Tag::MQ_DESTINATION] = $queue;
        }

        if ($connection) {
            $commonTags['messaging.laravel.connection'] = $connection;
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
        )->withExactTags(
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
        )->withExactTags(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
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
        )->withExactTags(
            $this->getCommonTags('process', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
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
        )->withExactTags(
            $this->getCommonTags('receive', $queue, $connection)
        );

        if ($queue === 'sync') {
            return $span;
        } else {
            return $span->withExistingTagsNames([
                Tag::MQ_MESSAGE_ID
            ]);
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
        )->withExactTags([
            Tag::HTTP_URL => 'http://localhost:9999/queue/workOn',
            Tag::HTTP_METHOD => 'GET',
            Tag::HTTP_STATUS_CODE => 200
        ])->withExactTags(
            $this->getCommonTags('receive', $queue, $connection)
        )->withExistingTagsNames([
            Tag::MQ_MESSAGE_ID
        ])->withChildren([
            $this->spanEventJobProcessing(),
            $this->spanQueueFire($connection, $queue, $resourceDetails)
                ->withChildren([
                    $this->spanQueueResolve($connection, $queue, $resourceDetails),
                    ($isFromBatch
                        ? $this->spanQueueAction($connection, $queue, $resourceDetails)
                            ->withExistingTagsNames([
                                'messaging.laravel.batch_id',
                                Tag::MQ_MESSAGE_ID
                            ])
                        : $this->spanQueueAction($connection, $queue, $resourceDetails)
                            ->withExistingTagsNames([
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
        )->withExactTags(
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
        )->withExactTags(
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
        )->withExactTags([
            Tag::SPAN_KIND      => 'client',
            Tag::COMPONENT      => 'laravelqueue',
            Tag::MQ_OPERATION   => 'send'
        ])->withExistingTagsNames([
            'messaging.laravel.batch_id'
        ]);
    }
}
