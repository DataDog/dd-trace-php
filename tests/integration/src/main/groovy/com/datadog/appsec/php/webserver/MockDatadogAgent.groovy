package com.datadog.appsec.php.webserver

import com.google.common.collect.Lists
import com.google.common.collect.Maps
import com.sun.net.httpserver.HttpExchange
import com.sun.net.httpserver.HttpHandler
import com.sun.net.httpserver.HttpServer
import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import org.msgpack.core.MessageFormat
import org.msgpack.core.MessagePack
import org.msgpack.core.MessageUnpacker
import org.msgpack.value.ValueType
import org.testcontainers.lifecycle.Startable

import java.nio.charset.StandardCharsets
import java.util.concurrent.Executors

@Slf4j
@CompileStatic
class MockDatadogAgent implements Startable {
    boolean started
    HttpServer httpServer
    int port
    final List<Object> capturedTraces = []
    AssertionError savedError

    @Override
    void start() {
        this.httpServer = HttpServer.create()

        for (int port = 10100; port <= 11000; port++) {
            try {
                InetSocketAddress s = new InetSocketAddress('0.0.0.0', port)
                this.httpServer.bind(s, 0)
                this.port = port
            } catch (IOException e) {
                continue
            }
        }

        this.httpServer.executor = Executors.newFixedThreadPool(1)
        this.httpServer.createContext('/', new DDAgentHandler())
        this.httpServer.start()
        started = true
    }

    @Override
    void stop() {
        this.httpServer.stop(0)
        started = false
    }

    static void main(String[] args) {
        def ddAgent = new MockDatadogAgent()
        ddAgent.start()
        System.err.println "Listening on port ${ddAgent.port}"
        try {
            while (true) {
                println ddAgent.nextTrace(3600000)
            }
        } catch (InterruptedException ie) {
            System.err.println 'Exiting'
            ddAgent.stop()
        }
    }

    Object nextTrace(long timeoutInMs) {
        synchronized (capturedTraces) {
            if (savedError) {
                throw savedError
            }
            if (capturedTraces.size() == 0) {
                log.info("Waiting up to $timeoutInMs for Agent request")
                capturedTraces.wait(timeoutInMs)
                if (capturedTraces.size() == 0) {
                    if (savedError) {
                        throw new AssertionError("Error in mock agent http thread", savedError)
                    }
                    throw new AssertionError("No trace gotten within $timeoutInMs ms")
                } else {
                    log.info("Wait finished. Got a request")
                }
            }
            capturedTraces.pop()
        }
    }

    private final static byte[] PUT_TRACES_RESPONSE =
            '{"rate_by_service":{"service:appsec_int_tests,env:integration":1}}'.getBytes(StandardCharsets.ISO_8859_1)


    List<Object> drainTraces() {
        List<Object> traces = []
        synchronized (capturedTraces) {
            while (!capturedTraces.empty) {
                traces.push(capturedTraces.pop())
            }
        }
        traces
    }

    private class DDAgentHandler implements HttpHandler {
        @Override
        void handle(HttpExchange httpExchange) {
            def method = httpExchange.requestMethod
            def uri = httpExchange.requestURI as String
            log.info("Request ${method} ${uri}")

            List<Object> traces
            AssertionError error
            if ((method == 'POST' || method == 'PUT') && uri == '/v0.4/traces') {
                if (httpExchange.requestHeaders.getFirst('x-datadog-diagnostic-check') == '1') {
                    // we could check the validity of the input
                    log.info("Diagnostic check with body: ${httpExchange.requestBody.text}")
                } else {
                    try {
                        httpExchange.requestBody.withCloseable {
                            traces = readTraces(it)
                        }
                    } catch (AssertionError e) {
                        log.error("Error reading traces: $e.message")
                        error = e
                    }
                }

                // response
                httpExchange.responseHeaders.add('Content-type', 'application/json')
                httpExchange.sendResponseHeaders(200, PUT_TRACES_RESPONSE.length)
                OutputStream os = httpExchange.responseBody
                os.write(PUT_TRACES_RESPONSE)
                os.close()
            } else {
                httpExchange.sendResponseHeaders(500, 0)
                httpExchange.responseBody.close()
            }
            httpExchange.close()

            if (traces) {
                synchronized (capturedTraces) {
                    capturedTraces.addAll traces
                    capturedTraces.notify()
                }
            }
            if (error) {
                synchronized (capturedTraces) {
                    savedError = error
                    capturedTraces.notify()
                }
            }
        }
    }

    static List<Object> readTraces(InputStream is) {
        List<Object> traces = []
        MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(is)
        while (unpacker.hasNext()) {
            traces << unpackSingle(unpacker)
        }

        traces
    }

    static Object unpackSingle(MessageUnpacker unpacker) {
        MessageFormat format = unpacker.nextFormat
        ValueType type = format.valueType
        switch (type) {
            case ValueType.NIL:
                unpacker.unpackNil()
                return null
            case ValueType.BOOLEAN:
                 return unpacker.unpackBoolean();
            case ValueType.INTEGER:
                switch (format) {
                    case MessageFormat.UINT64:
                        return unpacker.unpackBigInteger()
                    case MessageFormat.INT64:
                    case MessageFormat.UINT32:
                        return unpacker.unpackLong()
                    default:
                        return unpacker.unpackInt()
                }
            case ValueType.FLOAT:
                    return unpacker.unpackDouble()
            case ValueType.STRING:
                return unpacker.unpackString()
            case ValueType.BINARY: {
                int length = unpacker.unpackBinaryHeader()
                byte[] data = new byte[length]
                unpacker.readPayload(data)
                return data
            }
            case ValueType.ARRAY: {
                int length = unpacker.unpackArrayHeader()
                def ret = Lists.newArrayListWithCapacity(length)
                for (int i = 0; i < length; i++) {
                    ret << unpackSingle(unpacker)
                }
                return ret
            }
            case ValueType.MAP: {
                int length = unpacker.unpackMapHeader()
                def ret = Maps.newHashMapWithExpectedSize(length)
                for (int i = 0; i < length; i++) {
                    def key = unpackSingle(unpacker)
                    def value = unpackSingle(unpacker)
                    ret[key] = value
                }
                return ret
            }
            case ValueType.EXTENSION:
                return null
        }
    }
}
