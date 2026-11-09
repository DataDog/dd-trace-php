package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.LogFile
import com.datadog.appsec.php.integration.Php2ShellHelpers.CarrierReq
import com.datadog.appsec.php.integration.Php2ShellHelpers.StartupReq
import com.datadog.appsec.php.integration.Php2ShellHelpers.TraceLog
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Test

import static com.datadog.appsec.php.integration.Php2ShellHelpers.ALT_TAGS_HEADER
import static com.datadog.appsec.php.integration.Php2ShellHelpers.ALT_TAG_KEY
import static com.datadog.appsec.php.integration.Php2ShellHelpers.AUTOFLUSH_HEADER
import static com.datadog.appsec.php.integration.Php2ShellHelpers.CARRIER_HOSTNAME
import static com.datadog.appsec.php.integration.Php2ShellHelpers.FAILED_HOSTNAME
import static com.datadog.appsec.php.integration.Php2ShellHelpers.FAILED_SERVICE
import static com.datadog.appsec.php.integration.Php2ShellHelpers.HOSTNAME_HEADER
import static com.datadog.appsec.php.integration.Php2ShellHelpers.METRIC_DEFERRED
import static com.datadog.appsec.php.integration.Php2ShellHelpers.NOROOT_HEADER
import static com.datadog.appsec.php.integration.Php2ShellHelpers.SERVICE_HEADER
import static com.datadog.appsec.php.integration.Php2ShellHelpers.STARTUP_METRIC_TAGS
import static com.datadog.appsec.php.integration.Php2ShellHelpers.assertCarrier
import static com.datadog.appsec.php.integration.Php2ShellHelpers.assertStartupSpan
import static com.datadog.appsec.php.integration.Php2ShellHelpers.failedRequestPid
import static com.datadog.appsec.php.integration.Php2ShellHelpers.metricSumsAtClose
import static com.datadog.appsec.php.integration.Php2ShellHelpers.sendCarrier
import static com.datadog.appsec.php.integration.Php2ShellHelpers.startupHeaders
import static com.datadog.appsec.php.integration.Php2ShellHelpers.startupSpanReq
import static com.datadog.appsec.php.integration.Php2ShellHelpers.uniqueService

/**
 * The startup span cases that run unchanged on php-fpm
 * (Php2ShellMitigationTests) and on Apache mod_php
 * (Php2ShellStartupSpanModTests): a request whose body crosses memory_limit
 * in the startup POST parse fails its request startup and has no root span,
 * and the next traced request sends its span as a trace of its own (see
 * Php2ShellHelpers).
 *
 * The two SAPIs differ in what happens to the process that failed. A php-fpm
 * worker exits after a fatal in the request startup and the master forks a
 * replacement, which shares the slot (it is created in the master), so the
 * next request runs in another process. A mod_php child survives the failed
 * startup and goes on serving requests; Apache may also run several children,
 * which all share the slot, so the next request may run in the child that
 * failed or in another one.
 *
 * An implementing class provides the container (a static CONTAINER field),
 * the setup each case runs in ({@link #startupSpanCase}) and the check of the
 * HTTP response of a failed request ({@link #sendFailed}).
 */
trait Php2ShellStartupSpanCases {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    // Runs `body` with the server set up for a startup span case, which also
    // gives the case a new, empty slot.
    abstract <T> T startupSpanCase(Closure<T> body)

    // Sends a request whose startup fails and checks the server's answer,
    // which depends on the SAPI; the span's status is what the cases check.
    abstract void sendFailed(StartupReq sr)

    @Test
    void 'f_startup_span: a request failed in the startup window is sent by the next traced request'() {
        startupSpanCase {
            // The carrier need not run in the process that failed, and the
            // span must report the failed process's id, which the
            // mitigation's warning in the AppSec log gives: the failed process
            // writes it before it answers, and only the failed request writes
            // one, so it is the only such line appended while this case runs.
            //
            // A process takes the configuration that its first request's
            // environment sets (here, with SetEnvIf) as its defaults. The
            // failed request reports a hostname, so if it were the first
            // request of its process, a later carrier in that process would
            // report the hostname too. The warm-up request runs first so that,
            // when the server has a single process, the failed request is not
            // its first one. With several processes, the warm-up may run in
            // another process than the failed request.
            CarrierReq warmUp = sendCarrier(container)
            Span warmUpRoot = container.nextCapturedTraces([warmUp.traceId])[warmUp.traceId].rootSpan
            LogFile appsecLog = new LogFile(container, 'appsec.log')
            appsecLog.markEndPos()
            long t0 = System.currentTimeMillis() * 1_000_000L
            // Only the failed request gets the service FAILED_SERVICE and
            // reports a hostname: the span must carry the configuration
            // resolved for it.
            StartupReq failed = startupSpanReq(container, startupHeaders(
                    [(SERVICE_HEADER): FAILED_SERVICE, (HOSTNAME_HEADER): FAILED_HOSTNAME]))
            sendFailed(failed)
            long failedPid = failedRequestPid(appsecLog)
            CarrierReq carrier = sendCarrier(container)
            Map<BigInteger, Trace> traces = container.nextCapturedTraces([failed.traceId, carrier.traceId])
            Trace carrierTrace = traces[carrier.traceId]
            assertStartupSpan(traces[failed.traceId], failed,
                    service: FAILED_SERVICE, t0: t0, carrier: carrierTrace, hostname: FAILED_HOSTNAME)

            // The span reports the identity of the failed process. Its runtime
            // id is compared with the ids of other requests that ran in that
            // process, when there are any: nothing else the failed process
            // wrote reports its runtime id.
            Span span = traces[failed.traceId].first()
            Span carrierRoot = carrierTrace.rootSpan
            assert span.metrics.process_id as long == failedPid
            assert span.meta['runtime-id']
            boolean warmUpInFailedProcess = (warmUpRoot.metrics.process_id as long) == failedPid
            boolean carrierInFailedProcess = (carrierRoot.metrics.process_id as long) == failedPid
            if (warmUpInFailedProcess) {
                assert span.meta['runtime-id'] == warmUpRoot.meta['runtime-id']
            }
            if (carrierInFailedProcess) {
                assert span.meta['runtime-id'] == carrierRoot.meta['runtime-id']
            } else {
                assert span.meta['runtime-id'] != carrierRoot.meta['runtime-id']
            }

            // The carrier reports no hostname, unless it ran in the failed
            // process and the failed request was that process's first one
            // (the warm-up ran elsewhere): then the hostname is that process's
            // default.
            assertCarrier(carrierTrace, carrier,
                    checkHostname: !(carrierInFailedProcess && !warmUpInFailedProcess))
        }
    }

    @Test
    void 'g_startup_span_burst: startup fatals that find the slot taken are counted on its span'() {
        startupSpanCase {
            TraceLog received = new TraceLog(container)
            // Each of the burst's requests has a service of its own, under
            // which its metric points are reported: no earlier case's points
            // can add to the counts below, and each count is one request's.
            List<String> services = (1..3).collect { uniqueService('p2s-burst') }
            long t0 = System.currentTimeMillis() * 1_000_000L
            // Back to back, with no request in between that could take the
            // event. On php-fpm, each failure ends its worker, and its
            // replacement shares the slot. On mod_php, the requests may run in
            // different children, or, under the event MPM, on different
            // threads of one child, each thread with its own sidecar
            // connection.
            List<StartupReq> burst = services.collect {
                startupSpanReq(container, startupHeaders([(SERVICE_HEADER): it]))
            }
            burst.each { sendFailed(it) }
            CarrierReq carrier = sendCarrier(container)
            Map<BigInteger, Trace> traces = received.next([burst[0].traceId, carrier.traceId])
            assertStartupSpan(traces[burst[0].traceId], burst[0],
                    service: services[0], dropped: 2, t0: t0, carrier: traces[carrier.traceId])
            assertCarrier(traces[carrier.traceId], carrier)
            // The slot is empty again: the next request sends only its own
            // trace, and nothing else arrives behind it.
            CarrierReq next = sendCarrier(container)
            assertCarrier(received.next([next.traceId])[next.traceId], next)
            received.barrier()
            received.assertNotSent(burst*.traceId)
            // The metric still counts every startup fatal, once.
            assert metricSumsAtClose(container, services, METRIC_DEFERRED, STARTUP_METRIC_TAGS) ==
                    services.collectEntries { [(it): 1d] }
        }
    }

    @Test
    void 'h_startup_span_untraced_carrier: a request with root span generation off leaves the span to a traced one'() {
        startupSpanCase {
            long t0 = System.currentTimeMillis() * 1_000_000L
            StartupReq failed = startupSpanReq(container, startupHeaders())
            sendFailed(failed)
            TraceLog received = new TraceLog(container)
            CarrierReq noroot = sendCarrier(container, [(NOROOT_HEADER): '1'])
            // This carrier has header tags of its own, which the span then
            // shows: this carrier sent it, not the earlier one (a copy sent
            // by that one would also arrive first, and the second copy
            // fails). It also reports a hostname, which the span, of a
            // request that reported none, must not take.
            CarrierReq carrier = sendCarrier(container,
                    [(ALT_TAGS_HEADER): '1', (HOSTNAME_HEADER): CARRIER_HOSTNAME])
            Map<BigInteger, Trace> traces = received.next([failed.traceId, carrier.traceId])
            assertStartupSpan(traces[failed.traceId], failed,
                    t0: t0, carrier: traces[carrier.traceId], tagKey: ALT_TAG_KEY)
            assertCarrier(traces[carrier.traceId], carrier,
                    tagKey: ALT_TAG_KEY, hostname: CARRIER_HOSTNAME, appsec: true)
            received.barrier()
            received.assertNotSent([failed.traceId, noroot.traceId])
        }
    }

    @Test
    void 'i_startup_span_untraced_failure: nothing is saved for a failed request with root span generation off'() {
        startupSpanCase {
            StartupReq noroot = startupSpanReq(container, startupHeaders([(NOROOT_HEADER): '1']))
            sendFailed(noroot)
            long t0 = System.currentTimeMillis() * 1_000_000L
            StartupReq failed = startupSpanReq(container, startupHeaders())
            sendFailed(failed)
            // With the auto flush on, closing the span at the carrier's RINIT
            // flushes it during the request startup, which must send it.
            TraceLog received = new TraceLog(container)
            CarrierReq carrier = sendCarrier(container, [(AUTOFLUSH_HEADER): '1'])
            Map<BigInteger, Trace> traces = received.next([failed.traceId, carrier.traceId])
            // The slot was still empty when the second request failed, and
            // the first is not counted as dropped.
            assertStartupSpan(traces[failed.traceId], failed, t0: t0, carrier: traces[carrier.traceId])
            assertCarrier(traces[carrier.traceId], carrier)
            received.barrier()
            received.assertNotSent([noroot.traceId])
        }
    }
}
