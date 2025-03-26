<?php

namespace DDTrace\Tests\Integrations\Ratchet\V0_4;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanChecker;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\Loop;

class RatchetTest extends IntegrationTestCase
{
    protected function ddSetUp()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_AUTO_FLUSH_ENABLED=0',
        ]);
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_AUTO_FLUSH_ENABLED',
            'DD_TRACE_GENERATE_ROOT_SPAN',
        ];
    }

    public function testRatchetConnect()
    {
        $traces = $this->isolateTracer(function () {
            $server = new class implements \Ratchet\WebSocket\MessageComponentInterface {
                public function onMessage(ConnectionInterface $from, MessageInterface $msg) {
                    $from->send($msg);
                }

                public function onOpen(ConnectionInterface $conn) {
                }

                public function onClose(ConnectionInterface $conn) {
                    Loop::stop();
                }

                public function onError(ConnectionInterface $conn, \Exception $e) {
                }
            };

            $sock = new \React\Socket\Server('0.0.0.0:0', Loop::get());
            $portParts = explode(":", $sock->getAddress());
            $port = end($portParts);

            $wsServer = new \Ratchet\WebSocket\WsServer($server);
            $app = new \Ratchet\Http\HttpServer($wsServer);
            new \Ratchet\Server\IoServer($app, $sock, Loop::get());

            \Ratchet\Client\connect("ws://127.0.0.1:$port")->then(function($conn) {
                $conn->on('message', function($msg) use ($conn) {
                    $this->assertSame($msg->getPayload(), "Hello World!");
                    $conn->send("Hello Datadog!");
                    $conn->close();
                });

                $conn->send('Hello World!');
            }, function ($e) {
                echo "Could not connect: {$e->getMessage()}\n";
            });

            Loop::run();
        });
        Loop::set(Factory::create()); // reset event loop

        foreach ($traces[0] as &$span) {
            $span["resource"] = preg_replace('(:(\d+)$)', ':%d', $span["resource"]);
            // Our checker has problems with two root spans being identical in primary attributes
            if ($span["resource"] === "websocket /" && ($span["metrics"]['websocket.message.length'] ?? "") == '12') {
                $span["resource"] .= 2;
            }
        }

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('websocket.send', 'phpunit', 'websocket', 'websocket ws://127.0.0.1:%d')
                ->withExactTags([
                    'span.kind' => 'producer',
                    'component' => 'ratchet',
                    'websocket.message.type' => 'text',
                ])
                ->withExactMetrics([
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                ])
                ->withExistingTagsNames(["_dd.span_links"]),
            SpanAssertion::build('Ratchet\Client\Connector.__invoke', 'ratchet', 'http', 'ws://127.0.0.1:%d')
                ->withExactTags([
                    'span.kind' => 'client',
                    'component' => 'ratchet',
                    'http.method' => 'GET',
                    'http.url' => 'ws://127.0.0.1:%d',
                    'network.destination.name' => '127.0.0.1',
                    'http.status_code' => '101',
                    'http.upgraded' => '1',
                    "_dd.base_service" => "phpunit",
                ]),
            SpanAssertion::build('websocket.close', 'ratchet', 'websocket', 'websocket /')
                ->withExactTags([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    'websocket.close.code' => '1000',
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                    '_dd.p.dm' => "-0",
                ])
                ->withExactMetrics([
                    '_dd.dm.inherited' => 1,
                    '_sampling_priority_v1' => 1,
                ])
                ->withExistingTagsNames(["_dd.span_links"])
                ->withChildren([
                    SpanAssertion::build('websocket.close', 'ratchet', 'websocket', 'websocket /')
                        ->withExactTags([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            'websocket.close.code' => '1000',
                        ])
                        ->withExistingTagsNames(["_dd.span_links"])
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket /')
                ->withExactTags([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                    '_dd.p.dm' => "-0",
                ])
                ->withExactMetrics([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 14,
                    'websocket.message.frames' => 1,
                    '_sampling_priority_v1' => 1,
                ])
                ->withExistingTagsNames(["_dd.span_links"])
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket /')
                        ->withExactTags([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactMetrics([
                            'websocket.message.length' => 14,
                            'websocket.message.frames' => 1,
                        ])
                        ->withExistingTagsNames(["_dd.span_links"])
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket ws://127.0.0.1:%d')
                ->withExactTags([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'phpunit',
                    '_dd.dm.resource' => '',
                    '_dd.p.dm' => "-0",
                ])
                ->withExactMetrics([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                    '_sampling_priority_v1' => 1,
                ])
                ->withExistingTagsNames(["_dd.span_links", 'closure.declaration'])
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket ws://127.0.0.1:%d')
                        ->withExactTags([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactMetrics([
                            'websocket.message.length' => 14,
                            'websocket.message.frames' => 1,
                        ])
                        ->withExistingTagsNames(["_dd.span_links"])
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket /2')
                ->withExactTags([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                    '_dd.p.dm' => "-0",
                ])
                ->withExactMetrics([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                    '_sampling_priority_v1' => 1,
                ])
                ->withExistingTagsNames(["_dd.span_links"])
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket /2')
                        ->withExactTags([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactMetrics([
                            'websocket.message.length' => 12,
                            'websocket.message.frames' => 1,
                        ])
                        ->withExistingTagsNames(["_dd.span_links"])
                ]),
            SpanAssertion::build('web.request', 'ratchet', 'web', 'GET /')
                ->withExactTags([
                    'component' => 'ratchet',
                    'span.kind' => 'server',
                    'http.url' => 'http://127.0.0.1:%d/',
                    'http.method' => 'GET',
                    'http.useragent' => 'Ratchet-Pawl/0.4.1',
                    'http.status_code' => '101',
                    'http.upgraded' => '1',
                ]),
        ]);
    }

    public function testRatchetConnectFail()
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
        ]);

        $traces = $this->isolateTracer(function () {
            \Ratchet\Client\connect('ws://127.0.0.1:1')->then(function() {}, function ($ex) use (&$e) {
                $e = $ex;
            });
            Loop::run();
            $this->assertInstanceOf(\RuntimeException::class, $e);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Ratchet\Client\Connector.__invoke', 'ratchet', 'http', 'ws://127.0.0.1:1')
                ->withExactTags([
                    "span.kind" => "client",
                    "component" => "ratchet",
                    "http.method" => "GET",
                    "http.url" => "ws://127.0.0.1:1",
                    "network.destination.name" => "127.0.0.1",
                ])
                ->setError("RuntimeException", "", true)
        ]);
    }
}