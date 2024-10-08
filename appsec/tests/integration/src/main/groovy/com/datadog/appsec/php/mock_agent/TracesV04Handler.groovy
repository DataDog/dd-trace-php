package com.datadog.appsec.php.mock_agent

import com.datadog.appsec.php.model.Mapper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import io.javalin.http.BadRequestResponse
import io.javalin.http.Context
import io.javalin.http.Handler
import org.jetbrains.annotations.NotNull
import org.msgpack.core.MessagePack
import org.msgpack.core.MessageUnpacker

@Slf4j
@CompileStatic
@Singleton
class TracesV04Handler implements Handler {
    private final static String PUT_TRACES_RESPONSE =
            '{"rate_by_service":{"service:appsec_int_tests,env:integration":1}}'

    final List<Object> capturedTraces = []
    AssertionError savedError

    @Override
    void handle(@NotNull Context ctx) {
        if (ctx.header('x-datadog-diagnostic-check') == '1') {
            // we could check the validity of the input
            log.info("Diagnostic check with body: ${ctx.body()}")
            return
        }

        List<Object> traces
        AssertionError error
        try {
            ctx.bodyInputStream().withCloseable {
                traces = readTraces(it)
            }
        } catch (AssertionError e) {
            log.error("Error reading traces: $e.message")
            error = e
        }

        // response
        ctx.contentType('application/json')
                .result(PUT_TRACES_RESPONSE)

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
                throw new BadRequestResponse(error.message)
            }
        }

    }

    Trace nextTrace(long timeoutInMs) {
        def traceUntyped
        synchronized (capturedTraces) {
            if (savedError) {
                throw savedError
            }
            if (capturedTraces.size() == 0) {
                log.info("Waiting up to $timeoutInMs ms for a trace")
                capturedTraces.wait(timeoutInMs)
                if (savedError) {
                    throw new AssertionError('Error in mock agent http thread', savedError)
                }
                if (capturedTraces.size() == 0) {
                    throw new AssertionError("No trace gotten within $timeoutInMs ms" as Object)
                } else {
                    log.info('Wait finished. Last gotten: {}', capturedTraces.last())
                    if (capturedTraces.size() > 1) {
                        log.info("There are a total of ${capturedTraces.size()} traces stored")
                    }
                }
            }
            traceUntyped = capturedTraces.pop()
        }
        Mapper.INSTANCE.convertValue(traceUntyped, Trace)
    }

    List<Object> drainTraces() {
        List<Object> traces = []
        synchronized (capturedTraces) {
            while (!capturedTraces.empty) {
                traces.push(capturedTraces.pop())
            }
        }
        traces
    }

    private static List<Object> readTraces(InputStream is) {
        List<Object> traces = []
        MessageUnpacker unpacker = MessagePack.newDefaultUnpacker(is)
        while (unpacker.hasNext()) {
            def trace = MsgpackHelper.unpackSingle(unpacker)
            log.debug('Read submitted trace {}', trace)
            traces << trace
        }

        traces.first() as List<Object> ?: []
    }
}
