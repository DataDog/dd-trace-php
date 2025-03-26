<?php

namespace DDTrace\Integrations\Ratchet;

use DDTrace\HookData;
use DDTrace\Http\Urls;
use DDTrace\Integrations\HttpClientIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\RootSpanData;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\AbstractConnectionDecorator;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\ConnectionInterface;
use Ratchet\Http\CloseResponseTrait;
use Ratchet\Http\HttpServerInterface;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\DataInterface;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\Server\IoConnection;
use Ratchet\WebSocket\WsConnection;
use Ratchet\WebSocket\WsServer;
use function DDTrace\active_stack;
use function DDTrace\close_span;
use function DDTrace\create_stack;
use function DDTrace\get_priority_sampling;
use function DDTrace\start_span;
use function DDTrace\switch_stack;
use function DDTrace\UserRequest\notify_commit;
use function DDTrace\UserRequest\notify_start;

/**
 * Ratchet integration
 */
class RatchetIntegration extends Integration
{
    const NAME = 'ratchet';

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * @return int
     */
    public function init(): int
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;
        $service = \ddtrace_config_app_name('ratchet');

        \DDTrace\install_hook(Connector::class . "::__invoke", function (HookData $hook) {
            $url = $hook->args[0];
            create_stack();
            $hook->data = $span = start_span();
            $span->name = 'Ratchet\Client\Connector.__invoke';
            $span->resource = \DDTrace\Util\Normalizer::uriNormalizeOutgoingPath($url);
            $span->type = Type::HTTP_CLIENT;
            Integration::handleInternalSpanServiceName($span, RatchetIntegration::NAME);
            $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
            $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
            $span->meta[Tag::COMPONENT] = RatchetIntegration::NAME;
            $span->meta[Tag::HTTP_METHOD] = "GET";
            $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
            $span->meta[Tag::NETWORK_DESTINATION_NAME] = Urls::hostname($url);
            if (\dd_trace_env_config("DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN")) {
                $span->service = Urls::hostnameForTag($url);
            }
        }, function (HookData $hook) {
            $span = $hook->data;
            $rootSpan = \DDTrace\root_span();
            if ($hook->exception) {
                $span->exception = $hook->exception;
                close_span();
            } else {
                $hook->returned->then(function ($websocket) use ($span, $rootSpan) {
                    ObjectKVStore::put($websocket, "handshake", $span);
                    ObjectKVStore::put($websocket, "handshake_root", $rootSpan);

                    $span->meta[Tag::HTTP_STATUS_CODE] = $websocket->response->getStatusCode();
                    $span->meta["http.upgraded"] = '1';

                    $stackBefore = active_stack();
                    switch_stack($span);
                    get_priority_sampling(); // force a sampling decision
                    close_span();
                    switch_stack($stackBefore);
                }, function ($exception) use ($span) {
                    if ($exception instanceof \DomainException) {
                        // has the http status line as message
                        $parts = explode(" ", $exception->getMessage());
                        if (count($parts) == 3 && is_numeric($parts[1])) {
                            $span->meta[Tag::HTTP_STATUS_CODE] = (int)$parts[1];
                        } else {
                            $span->meta[Tag::HTTP_STATUS_CODE] = 101;
                        }
                    }
                    $span->exception = $exception;

                    $stackBefore = active_stack();
                    switch_stack($span);
                    close_span();
                    switch_stack($stackBefore);
                });
                switch_stack();
            }
        });

        \DDTrace\install_hook(HttpServerInterface::class . "::onOpen", function (HookData $hook) use ($service, $integration) {
            if (!\dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_ENABLED")) {
                return;
            }

            $hook->disableJitInlining();

            ini_set("datadog.trace.generate_root_span", 0);

            /** @var $req RequestInterface */
            /** @var $conn ConnectionInterface */
            list($conn, $req) = $hook->args;
            while ($conn instanceof AbstractConnectionDecorator) {
                $conn = (function () { return $this->getConnection(); })->call($conn);
            }

            $query = $req->getUri()->getQuery();

            $server = [
                'SERVER_PROTOCOL' => "HTTP/" . $req->getProtocolVersion(),
                'REQUEST_METHOD' => $req->getMethod(),
                'REQUEST_URI' => $req->getUri()->getPath() . ($query != "" ? "?{$query}" : ""),
                'SERVER_NAME' => $req->getUri()->getHost(),
                'SERVER_PORT' => $req->getUri()->getPort() ?? ($req->getUri()->getScheme() == "https" ? 443 : 80),
                'HTTP_HOST' => $req->getUri()->getHost(),
                'QUERY_STRING' => $query,
            ];
            if ($req->getUri()->getScheme() == "https") {
                $server['HTTPS'] = "on";
            }
            foreach ($req->getHeaders() as $name => $values) {
                $collapsedValue = implode(', ', $values);
                $name = preg_replace("/[^A-Z\d]/", "_", strtoupper($name));
                // these two have special treatment. See RFC 3875
                if ($name != 'CONTENT_TYPE' && $name != 'CONTENT_LENGTH') {
                    $name = "HTTP_$name";
                }
                $server[$name] = $collapsedValue;
            }

            $parentConn = $conn;

            if ($conn instanceof IoConnection) {
                $conn = (function () { return $this->conn; })->call($conn);
                /** @var \React\Socket\ConnectionInterface $conn */
                $server["REMOTE_ADDR"] = trim(parse_url($conn->getRemoteAddress(), PHP_URL_HOST), '[]');
                $server["SERVER_PORT"] = parse_url($conn->getLocalAddress(), PHP_URL_PORT);
            }

            $pseudoglobals['_SERVER'] = $server;
            parse_str($req->getUri()->getQuery(), $query);
            $pseudoglobals['_GET'] = $query;
            $pseudoglobals['_POST'] = []; // Let's not attempt to parse this here
            $pseudoglobals['_COOKIE'] = [];
            $pseudoglobals['_FILES'] = [];

            $activeSpan = $hook->span(new SpanStack);
            $activeSpan->service = $service;
            $activeSpan->name = "web.request";
            $activeSpan->type = Type::WEB_SERVLET;
            $activeSpan->meta[Tag::COMPONENT] = RatchetIntegration::NAME;
            $activeSpan->meta[Tag::SPAN_KIND] = 'server';
            $integration->addTraceAnalyticsIfEnabled($activeSpan);

            ObjectKVStore::put($parentConn, "handshake", $activeSpan);

            \DDTrace\consume_distributed_tracing_headers(function ($headername) use ($req) {
                return $req->getHeaderLine($headername);
            });

            if ($res = notify_start($activeSpan, $pseudoglobals)) {
                $conn->write("HTTP/{$req->getProtocolVersion()} {$res["status"]} Request Blocked\r\n" . implode("\r\n", $res["headers"]) . "\r\n\r\n{$res['body']}");
                notify_commit($activeSpan, $res["status"], $res["headers"]);
                $conn->close();
                $hook->suppressCall();
            }
        });

        \DDTrace\install_hook(CloseResponseTrait::class . "::close", function (HookData $hook) {
            if ($rootSpan = \DDTrace\root_span()) {
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $hook->args[1] ?? 400;
                notify_commit($rootSpan, 400, []);
            }
        });

        \DDTrace\install_hook(ServerNegotiator::class . "::handshake", null, function (HookData $hook) {
            if ($span = \DDTrace\root_span()) {
                /** @var ResponseInterface $response */
                $response = $hook->returned;
                if ($response->getStatusCode() === 101) {
                    $span->meta["http.upgraded"] = '1';
                }
                notify_commit($span, $response->getStatusCode(), $response->getHeaders());
            }
        });

        \DDTrace\install_hook(MessageBuffer::class . "::__construct", null, function () {
            if (!\dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_ENABLED")) {
                return;
            }

            // The onMessage Closure holds the Client\WebSocket as $this, and WsConnection as first argument
            $onMessage = $this->onMessage;
            $onControl = $this->onControl;
            $callbackReflection = new \ReflectionFunction($onMessage);
            $handler = $callbackReflection->getClosureThis();
            if (!$handler) {
                return;
            }
            $isServer = false;
            if ($handler instanceof WsServer) {
                $isServer = true;
                $rootSpan = $handshake = \DDTrace\root_span();
                get_priority_sampling(); // force a sampling decision
            } elseif (!($handler instanceof WebSocket)) {
                return;
            }

            $frameNum = 0;
            $hookFn = function ($isControl) use ($handler, &$handshake, &$rootSpan, $isServer, &$frameNum) {
                return function (HookData $hook) use ($isControl, $handler, &$handshake, &$rootSpan, $isServer, &$frameNum) {
                    // In the Websocket client case we only get hold of the websocket instance after it was constructed
                    // I.e. we need to fetch the handshake from the Client\WebSocket class ($handler).
                    if (!$handshake) {
                        if (!$handshake = ObjectKVStore::get($handler, "handshake")) {
                            return;
                        }

                        $rootSpan = ObjectKVStore::get($handler, "handshake_root", $handshake);
                    }

                    $message = $hook->args[0];
                    if ($isControl && $message->getOpcode() !== Frame::OP_CLOSE) {
                        return;
                    }

                    $rootTrace = \dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_SEPARATE_TRACES");
                    /** @var RootSpanData $span */
                    $span = $hook->span($rootTrace ? new SpanStack : null);
                    $span->type = Type::WEBSOCKET;
                    $span->service = $handshake->service;
                    $resourceParts = explode(" ", $handshake->resource, 2);
                    $span->resource = "websocket " . end($resourceParts);
                    $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CONSUMER;
                    $span->meta[Tag::COMPONENT] = RatchetIntegration::NAME;
                    if ($rootTrace && \dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_INHERIT_SAMPLING")) {
                        $span->samplingPriority = $rootSpan->samplingPriority;
                        if (isset($rootSpan->origin)) {
                            $span->origin = $rootSpan->origin;
                        }
                        $span->metrics["_dd.dm.inherited"] = 1;
                        if (!isset($rootSpan->parentId)) {
                            $span->meta["_dd.dm.service"] = $rootSpan->service;
                            $span->meta["_dd.dm.resource"] = $rootSpan->resource;
                        }
                        foreach ($rootSpan->propagatedTags as $key => $_) {
                            if (isset($rootSpan->meta[$key])) {
                                $span->meta[$key] = $rootSpan->meta[$key];
                            }
                        }
                        $span->baggage = $rootSpan->baggage;
                    }

                    if ($isControl) {
                        $span->name = "websocket.close";
                        $closePayload = $message->getPayload();
                        if (\strlen($closePayload) >= 2) {
                            $span->meta["websocket.close.code"] = unpack("n", $closePayload)[1];
                            if (\strlen($closePayload) > 2) {
                                $span->meta["websocket.close.reason"] = substr($closePayload, 2);
                            }
                        }
                    } else {
                        $span->name = "websocket.receive";
                        $span->meta["websocket.message.type"] = $message->isBinary() ? "binary" : "text";
                    }

                    if ($isServer) {
                        unset($span->meta["closure.declaration"]);
                    }

                    RatchetIntegration::addLink($span, $handshake, true, $isServer, $frameNum++);
                };
            };

            \DDTrace\install_hook($onMessage, $hookFn(false), function (HookData $hook) {
                $span = $hook->span();
                $message = $hook->args[0];
                $span->metrics["websocket.message.length"] = $message->getPayloadLength();
                $span->metrics["websocket.message.frames"] = $message->count();
            }, \DDTrace\HOOK_INSTANCE);

            \DDTrace\install_hook($onControl, $hookFn(true), null, \DDTrace\HOOK_INSTANCE);
        });

        \DDTrace\install_hook(WsConnection::class . "::send", function (HookData $hook) {
            if (!\dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_ENABLED")) {
                return;
            }

            if ($this->WebSocket->closing) {
                return;
            }

            $msg = $hook->args[0];
            if ($msg === null) {
                return;
            }

            $connection = $this->getConnection();
            RatchetIntegration::outgoingMessage($msg, $hook, $connection, true);
        });

        \DDTrace\install_hook(WebSocket::class . "::send", function (HookData $hook) {
            if (!\dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_ENABLED")) {
                return;
            }

            RatchetIntegration::outgoingMessage($hook->args[0], $hook, $this, false);
        });

        \DDTrace\install_hook(WsConnection::class . "::close", function (HookData $hook) {
            if (!\dd_trace_env_config("DD_TRACE_WEBSOCKET_MESSAGES_ENABLED")) {
                return;
            }

            if (!$handshake = ObjectKVStore::get($this, "handshake")) {
                return;
            }

            $code = $hook->args[0] ?? 1000;
            $reason = $hook->args[1] ?? "";

            $span = $hook->span();
            $span->type = Type::WEBSOCKET;
            $resourceParts = explode(" ", $handshake->resource, 2);
            $span->resource = "websocket " . end($resourceParts);
            $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;
            $span->meta[Tag::COMPONENT] = RatchetIntegration::NAME;
            $span->name = "websocket.close";
            $span->meta["websocket.close.code"] = $code;
            if ($reason != "") {
                $span->meta["websocket.close.reason"] = $reason;
            }
            $frameNum = ObjectKVStore::get($handshake, "frameNum");
            RatchetIntegration::addLink($span, $handshake, false, false, $frameNum);
        });

        return Integration::LOADED;
    }

    public static function outgoingMessage($msg, $hook, $handshakeContainer, $isServer)
    {
        if (!$handshake = ObjectKVStore::get($handshakeContainer, "handshake")) {
            return;
        }

        $opcode = Frame::OP_TEXT;
        $closePayload = null;
        if ($msg instanceof DataInterface) {
            $msgLen = $msg->getPayloadLength();
            if ($msg instanceof Frame) {
                $opcode = $msg->getOpcode();
                if ($opcode === Frame::OP_CLOSE) {
                    $closePayload = $msg->getPayload();
                }
                if ($opcode === Frame::OP_CONTINUE) {
                    if (null !== $sendSpan = ObjectKVStore::get($handshakeContainer, "lastSendSpan")) {
                        ++$sendSpan->metrics["websocket.message.frames"];
                    }
                    return;
                }
            }
        } else {
            $msgLen = \strlen($msg);
        }

        $span = $hook->span();

        $span->type = Type::WEBSOCKET;
        $resourceParts = explode(" ", $handshake->resource, 2);
        $span->resource = "websocket " . end($resourceParts);
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;
        $span->meta[Tag::COMPONENT] = self::NAME;
        if ($opcode === Frame::OP_CLOSE) {
            $span->name = "websocket.close";
            if (\strlen($closePayload) >= 2) {
                $span->meta["websocket.close.code"] = unpack("n", $closePayload)[1];
                if (\strlen($closePayload) > 2) {
                    $span->meta["websocket.close.reason"] = substr($closePayload, 2);
                }
            }
        } else {
            $span->name = "websocket.send";
            $span->meta["websocket.message.type"] = $opcode === Frame::OP_BINARY ? "binary" : "text";
            $span->metrics["websocket.message.length"] = $msgLen;
            $span->metrics["websocket.message.frames"] = 1;
            ObjectKVStore::put($handshakeContainer, "lastSendSpan", $span);
        }
        $frameNum = ObjectKVStore::get($handshake, "frameNum");
        self::addLink($span, $handshake, false, $isServer, ++$frameNum);
        ObjectKVStore::put($handshake, "frameNum", $frameNum);
    }

    public static function addLink(SpanData $span, SpanData $handshake, $incoming, $isServer, $frameNum)
    {
        $link = $handshake->getLink();
        $link->attributes["dd.kind"] = $incoming ? "executed_by" : "resuming";
        $span->links[] = $link;
        $pointer = new SpanLink;
        $pointer->spanId = "0000000000000000";
        $pointer->traceId = "00000000000000000000000000000000";
        $pointer->attributes = [
            "dd.kind" => "span-pointer",
            "ptr.kind" => "websocket",
            "ptr.dir" => $incoming ? "d" : "u",
            "ptr.hash" => ($isServer ? "S" : "C") . $link->traceId . $link->spanId . bin2hex(pack("N", $frameNum)),
        ];
        $span->links[] = $pointer;
    }
}
