<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class MessengerTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/public/index.php';
    }

    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/bin/console';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_CLI_ENABLED' => '1',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    public function testAsyncLuckyNumberNotification()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky number', '/lucky/number');
            $this->call($spec);
        });

        list($consumerTraces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS' => 'true',
        ], [], ['messenger:consume', 'async', '--limit=1']);

        // Filter out the orphans
        $consumerTrace = \array_values(\array_filter($consumerTraces, function ($trace) {
            return $trace[0]['metrics']['_sampling_priority_v1'] !== 0;
        }));

        $this->assertCount(1, $consumerTrace);

        $this->assertFlameGraph($consumerTrace, [
            SpanAssertion::build(
                'symfony.messenger.consume',
                'symfony_messenger_test',
                'queue',
                'async -> App\\Message\\LuckyNumberNotification'
            )->withExactTags([
                'messaging.symfony.bus' => 'messenger.bus.default',
                'messaging.symfony.message' => 'App\\Message\LuckyNumberNotification',
                Tag::MQ_DESTINATION => 'async',
                Tag::MQ_OPERATION => 'receive',
                Tag::SPAN_KIND => Tag::SPAN_KIND_VALUE_CONSUMER,
                Tag::MQ_SYSTEM => 'symfony',
                Tag::MQ_DESTINATION_KIND => 'queue',
                Tag::COMPONENT => 'symfonymessenger',
            ])->withExactMetrics([
                'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\BusNameStamp' => 1,
                'messaging.symfony.stamps.DDTrace\\Integrations\\SymfonyMessenger\\DDTraceStamp' => 1,
                'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Bridge\\Doctrine\\Transport\\DoctrineReceivedStamp' => 1,
                'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\TransportMessageIdStamp' => 1,
                '_sampling_priority_v1' => 1
            ])->withExistingTagsNames([
                Tag::MQ_MESSAGE_ID,
            ])->withChildren([
                SpanAssertion::exists('PDOStatement.execute'),
                SpanAssertion::exists('PDO.prepare'),
                SpanAssertion::exists('symfony.Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'),
                SpanAssertion::build(
                    'symfony.messenger.dispatch',
                    'symfony_messenger_test',
                    'queue',
                    'async -> App\\Message\\LuckyNumberNotification'
                )->withExactTags([
                    'messaging.symfony.bus' => 'messenger.bus.default',
                    'messaging.symfony.message' => 'App\\Message\LuckyNumberNotification',
                    Tag::MQ_DESTINATION => 'async',
                    Tag::MQ_OPERATION => 'process',
                    Tag::SPAN_KIND => Tag::SPAN_KIND_VALUE_INTERNAL,
                    Tag::MQ_SYSTEM => 'symfony',
                    Tag::MQ_DESTINATION_KIND => 'queue',
                    Tag::COMPONENT => 'symfonymessenger',
                ])->withExactMetrics([
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\BusNameStamp' => 1,
                    'messaging.symfony.stamps.DDTrace\\Integrations\\SymfonyMessenger\\DDTraceStamp' => 1,
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Bridge\\Doctrine\\Transport\\DoctrineReceivedStamp' => 1,
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\TransportMessageIdStamp' => 1,
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\ReceivedStamp' => 1,
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\ConsumedByWorkerStamp' => 1,
                    'messaging.symfony.stamps.Symfony\\Component\\Messenger\\Stamp\\AckStamp' => 1,
                ])->withExistingTagsNames([
                    Tag::MQ_MESSAGE_ID,
                ])->withChildren([
                    SpanAssertion::build(
                        'symfony.messenger.handle',
                        'symfony_messenger_test',
                        'queue',
                        'App\\MessageHandler\\LuckyNumberNotificationHandler'
                    )->withExactTags([
                        'messaging.symfony.message' => 'App\\Message\\LuckyNumberNotification',
                        Tag::MQ_OPERATION => 'process',
                        Tag::SPAN_KIND => Tag::SPAN_KIND_VALUE_INTERNAL,
                        Tag::MQ_SYSTEM => 'symfony',
                        Tag::MQ_DESTINATION_KIND => 'queue',
                        Tag::COMPONENT => 'symfonymessenger',
                    ])
                ]),
                SpanAssertion::exists('symfony.Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent'),
            ])
        ]);
    }
}
