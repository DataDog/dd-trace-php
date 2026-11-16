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

        foreach ($traces as &$trace) {
            foreach ($trace['spans'] as &$span) {
                $span["resource"] = preg_replace('(:(\d+)$)', ':%d', $span["resource"]);
                // Our checker has problems with two root spans being identical in primary attributes
                if ($span["resource"] === "websocket /" && ($span["attributes"]['websocket.message.length'] ?? "") == '12') {
                    $span["resource"] .= 2;
                }
            }
            unset($span);
        }
        unset($trace);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('websocket.send', 'phpunit', 'websocket', 'websocket ws://127.0.0.1:%d')
                ->withExactAttributes([
                    'span.kind' => 'producer',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'm',
                    'websocket.message.type' => 'text',
                ])
                ->withExactAttributes([
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                ])
                ->withSpanLinks(),
            SpanAssertion::build('Ratchet\Client\Connector.__invoke', 'ratchet', 'http', 'ws://127.0.0.1:%d')
                ->withExactAttributes([
                    'span.kind' => 'client',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'http.method' => 'GET',
                    'http.url' => 'ws://127.0.0.1:%d',
                    'network.destination.name' => '127.0.0.1',
                    'http.status_code' => '101',
                    'http.upgraded' => true,
                    "_dd.base_service" => "phpunit",
                ]),
            SpanAssertion::build('websocket.close', 'ratchet', 'websocket', 'websocket /')
                ->withExactAttributes([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'websocket.close.code' => 1000,
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                ])->samplingMechanism(0)
                ->withExactAttributes([
                    '_dd.dm.inherited' => 1,
                ])->samplingPriority(1)
                ->withSpanLinks()
                ->withChildren([
                    SpanAssertion::build('websocket.close', 'ratchet', 'websocket', 'websocket /')
                        ->withExactAttributes([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            '_dd.svc_src' => 'ratchet',
                            'websocket.close.code' => 1000,
                        ])
                        ->withSpanLinks()
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket /')
                ->withExactAttributes([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                ])->samplingMechanism(0)
                ->withExactAttributes([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 14,
                    'websocket.message.frames' => 1,
                ])->samplingPriority(1)
                ->withSpanLinks()
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket /')
                        ->withExactAttributes([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            '_dd.svc_src' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactAttributes([
                            'websocket.message.length' => 14,
                            'websocket.message.frames' => 1,
                        ])
                        ->withSpanLinks()
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket ws://127.0.0.1:%d')
                ->withExactAttributes([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'phpunit',
                    '_dd.dm.resource' => '',
                ])->samplingMechanism(0)
                ->withExactAttributes([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                ])->samplingPriority(1)
                ->withExistingAttributeNames(['closure.declaration'])->withSpanLinks()
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket ws://127.0.0.1:%d')
                        ->withExactAttributes([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            '_dd.svc_src' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactAttributes([
                            'websocket.message.length' => 14,
                            'websocket.message.frames' => 1,
                        ])
                        ->withSpanLinks()
                ]),
            SpanAssertion::build('websocket.receive', 'ratchet', 'websocket', 'websocket /2')
                ->withExactAttributes([
                    'span.kind' => 'consumer',
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'websocket.message.type' => 'text',
                    '_dd.dm.service' => 'ratchet',
                    '_dd.dm.resource' => 'GET /',
                ])->samplingMechanism(0)
                ->withExactAttributes([
                    '_dd.dm.inherited' => 1,
                    'websocket.message.length' => 12,
                    'websocket.message.frames' => 1,
                ])->samplingPriority(1)
                ->withSpanLinks()
                ->withChildren([
                    SpanAssertion::build('websocket.send', 'ratchet', 'websocket', 'websocket /2')
                        ->withExactAttributes([
                            'span.kind' => 'producer',
                            'component' => 'ratchet',
                            '_dd.svc_src' => 'ratchet',
                            'websocket.message.type' => 'text',
                        ])
                        ->withExactAttributes([
                            'websocket.message.length' => 12,
                            'websocket.message.frames' => 1,
                        ])
                        ->withSpanLinks()
                ]),
            SpanAssertion::build('web.request', 'ratchet', 'web', 'GET /')
                ->withExactAttributes([
                    'component' => 'ratchet',
                    '_dd.svc_src' => 'ratchet',
                    'span.kind' => 'server',
                    'http.url' => 'http://127.0.0.1:%d/',
                    'http.method' => 'GET',
                    'http.useragent' => 'Ratchet-Pawl/0.4.1',
                    'http.status_code' => '101',
                    'http.upgraded' => true,
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
                ->withExactAttributes([
                    "span.kind" => "client",
                    "component" => "ratchet",
                    "_dd.svc_src" => "ratchet",
                    "http.method" => "GET",
                    "http.url" => "ws://127.0.0.1:1",
                    "network.destination.name" => "127.0.0.1",
                ])
                ->setError("RuntimeException", "", true)
        ]);
    }
}