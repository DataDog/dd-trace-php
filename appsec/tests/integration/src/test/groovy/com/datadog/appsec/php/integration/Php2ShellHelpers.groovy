package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.LogFile
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Helpers shared by the PHP2Shell integration tests for the startup span
 * cases, which run on both php-fpm and mod_php.
 *
 * A startup span case sends a request whose body crosses memory_limit in the
 * startup POST parse (the request startup fails and the request has no root
 * span of its own), then a normal request, the carrier, whose flush sends the
 * failed request's span as a trace of its own.
 *
 * Apache maps some request headers to the request's environment (see
 * {@link #STARTUP_CONF_LINES}), which both SAPIs read as configuration on
 * every request: they turn root span generation off for that request, turn
 * the auto flush on, set its service, report a hostname, or change its header
 * tags.
 * That lets a single request run with a configuration of its own without
 * changing the server's configuration.
 */
@Slf4j
class Php2ShellHelpers {
    static final long MIB = 1024L * 1024L
    static final String DEFERRED_SUFFIX = 'Datadog AppSec PHP2Shell mitigation deferred'
    static final String TAG_PREFIX = '_dd.appsec.php2shell.'
    static final String TAG_STARTUP_DROPPED = '_dd.appsec.php2shell.startup_dropped'
    // The operation names of the startup span and of a request's root span.
    static final String STARTUP_SPAN_NAME = 'web.request.startup_failure'
    static final String ROOT_SPAN_NAME = 'web.request'
    // The metric of the deferred fatals, and the tags of its points for a
    // startup fatal under the mitigation mode on.
    static final String METRIC_DEFERRED = 'appsec.php2shell.soft_limit_deferred'
    static final List<String> STARTUP_METRIC_TAGS = ['mode:on', 'phase:startup']

    // The startup span cases run under memory_limit=8M and post_max_size=32M,
    // with one urlencoded field name of 3 x memory_limit: as in
    // Php2ShellMitigationTests.a_bounded, the unfinished name crosses
    // memory_limit in the startup POST parse, the mitigation defers the fatal
    // to the end of the parse and the request startup fails.
    static final long STARTUP_LIMIT = 8L * MIB
    static final String STARTUP_MEMORY_LIMIT = '8M'
    static final String STARTUP_POST_MAX_SIZE = '32M'
    static final long STARTUP_FIELD_LEN = 3L * STARTUP_LIMIT

    static final String STARTUP_PATH = '/php2shell_sink.php?key1=val1&password=something'
    static final String CARRIER_PATH = '/hello.php'
    static final String NOROOT_HEADER = 'X-P2S-Noroot'
    static final String AUTOFLUSH_HEADER = 'X-P2S-Autoflush'
    static final String SERVICE_HEADER = 'X-P2S-Service'
    static final String FAILED_SERVICE = 'p2s-failed-svc'
    // Turns hostname reporting on for a request, with the header's value as
    // the hostname.
    static final String HOSTNAME_HEADER = 'X-P2S-Hostname'
    static final String FAILED_HOSTNAME = 'p2s-failed-host'
    static final String CARRIER_HOSTNAME = 'p2s-carrier-host'
    // A header tag configured with DD_TRACE_HEADER_TAGS (HEADER_TAGS), and
    // the tag it gets under the per-request HEADER_TAGS_ALT, which
    // ALT_TAGS_HEADER selects.
    static final String TAG_HEADER = 'X-P2S-Tag'
    static final String HEADER_TAGS = 'x-p2s-tag:p2s.tag'
    static final String TAG_KEY = 'p2s.tag'
    static final String ALT_TAGS_HEADER = 'X-P2S-Alttags'
    static final String ALT_TAG_KEY = 'p2s.alt'
    static final String CLIENT_IP = '1.2.3.4'
    static final String USER_AGENT = 'p2s-ua'
    // Distributed tracing context of the failed requests beyond the trace
    // and parent ids: an origin and a propagated tag.
    static final String ORIGIN = 'p2s-origin'
    static final String PROPAGATED_TAG = '_dd.p.p2s'
    static final String CARRIER_USER_AGENT = 'p2s-carrier-ua'

    static final String STARTUP_CONF = '/etc/apache2/conf-enabled/p2s-startup.conf'
    // Apache server configuration for the startup span cases. None of these
    // settings is a system setting, so a per-request value from the SAPI
    // environment (mod_php's subprocess_env, mod_proxy_fcgi's FastCGI
    // parameters) applies to that request.
    static final List<String> STARTUP_CONF_LINES = [
            "SetEnvIf ${NOROOT_HEADER} \"^1\$\" DD_TRACE_GENERATE_ROOT_SPAN=0".toString(),
            "SetEnvIf ${AUTOFLUSH_HEADER} \"^1\$\" DD_TRACE_AUTO_FLUSH_ENABLED=1".toString(),
            "SetEnvIf ${SERVICE_HEADER} \"^(.+)\$\" DD_SERVICE=\$1".toString(),
            "SetEnvIf ${HOSTNAME_HEADER} \"^(.+)\$\" DD_TRACE_REPORT_HOSTNAME=1 DD_HOSTNAME=\$1".toString(),
            "SetEnvIf ${ALT_TAGS_HEADER} \"^1\$\" DD_TRACE_HEADER_TAGS=x-p2s-tag:${ALT_TAG_KEY}".toString(),
    ]

    private static final Random RAND = new Random()

    // A request whose body crosses memory_limit in the startup POST parse,
    // with Datadog distributed tracing headers (a random trace id and parent
    // id, an origin, a propagated tag, and a user reject that the AppSec keep
    // must override).
    static class StartupReq {
        HttpRequest req
        BigInteger traceId
        BigInteger parentId
    }

    static StartupReq startupSpanReq(AppSecContainer c, Map<String, String> headers = [:]) {
        BigInteger parentId = new BigInteger(62, RAND) + 1
        HttpRequest req = c.buildStreamedReq(STARTUP_PATH,
                ['x-datadog-parent-id': parentId.toString(), 'x-datadog-sampling-priority': '-1',
                 'x-datadog-origin': ORIGIN, 'x-datadog-tags': "${PROPAGATED_TAG}=1".toString()] + headers,
                'f'.bytes, (byte) 0x5A /* 'Z' */, STARTUP_FIELD_LEN, '[k]=1'.bytes)
        new StartupReq(req: req, traceId: AppSecContainer.traceIdOf(req), parentId: parentId)
    }

    // Sends a startup span request; the HTTP status the server answered
    // (-1 if the connection broke).
    static int sendStartupReq(AppSecContainer c, StartupReq sr) {
        postReq(c, sr.req)
    }

    // A normal request, which may carry a startup span. It has a header tag
    // value, a user agent and a parent of its own, so that its trace can be
    // told from the failed request's.
    static class CarrierReq {
        BigInteger traceId
        BigInteger parentId
    }

    // A GET of `path` (default CARRIER_PATH) with `headers`, which must
    // answer `status` (default 200).
    static CarrierReq sendCarrier(AppSecContainer c, Map<String, String> headers = [:],
                                  String path = CARRIER_PATH, int status = 200) {
        BigInteger parentId = new BigInteger(62, RAND) + 1
        def br = c.buildReq(path).GET()
        (['x-datadog-parent-id': parentId.toString(), 'User-Agent': CARRIER_USER_AGENT, (TAG_HEADER): 'c'] +
                headers).each { k, v -> br.setHeader(k, v) }
        HttpRequest req = br.build()
        HttpResponse<String> resp = c.httpClient.send(req, ofString())
        assert resp.statusCode() == status
        new CarrierReq(traceId: AppSecContainer.traceIdOf(req), parentId: parentId)
    }

    static int postReq(AppSecContainer c, HttpRequest req) {
        try {
            HttpResponse<String> resp = c.httpClient.send(req, ofString())
            log.info("attack response status=${resp.statusCode()} body=${resp.body()?.take(120)}")
            return resp.statusCode()
        } catch (IOException e) {
            // A hard-limit _exit(137) tears the worker down mid-response; the
            // connection reset surfaces as an IOException.
            log.info("attack request connection error (expected for hard exit): ${e.message}")
            return -1
        }
    }

    /**
     * The traces a startup span case received, all of them, so that the
     * negative checks also see the traces nobody waited for: those that
     * arrived before an awaited one, and those behind it.
     *
     * <p>Checking for a trace that must not arrive needs a point after which
     * it would have arrived. The containers use the tracer's in-process
     * sender (DD_TRACE_SIDECAR_TRACE_SENDER=0), and a request's flush sends
     * all its closed traces in one payload, which the mock agent adds at
     * once: a startup span that a carrier takes travels with the carrier's
     * own trace. Across requests of one process, the sender's thread takes
     * the current buffer at the top of an iteration and then sends every
     * buffer taken so far, in turn. So once a carrier's trace has arrived, a
     * trace written earlier by the same process has arrived or is in a
     * buffer sent in the same iteration; a second carrier, sent after that,
     * is written to a buffer that iteration does not send, so its arrival
     * means the earlier trace arrived. {@link #barrier} sends those two
     * carriers. That holds only for the process the barrier runs in. The
     * startup span cases run a single php-fpm worker, and a single Apache
     * child except under SSI, so there a barrier runs in the process of the
     * requests it is meant to follow. Under SSI, Apache runs several
     * children and a barrier can run in another one: a trace that should not
     * have been sent can then arrive after assertNotSent and go unnoticed,
     * though the check never fails wrongly. The same cases also run without
     * SSI, where it is exact.
     */
    @Slf4j
    static class TraceLog {
        final AppSecContainer c
        final List<Trace> seen = []
        // The traces next() returned: the awaited ones.
        final Set<Trace> awaited = Collections.newSetFromMap(new IdentityHashMap<Trace, Boolean>())

        TraceLog(AppSecContainer c) {
            this.c = c
        }

        // Waits until a trace per id in `ids` has arrived and returns them.
        // Every trace received on the way is kept; a second trace with the id
        // of one received earlier fails.
        Map<BigInteger, Trace> next(Collection<BigInteger> ids) {
            Map<BigInteger, Trace> got = [:]
            while (!got.keySet().containsAll(ids)) {
                Trace t = c.nextCapturedTrace() as Trace
                record(t)
                if (t.traceId in ids) {
                    got[t.traceId] = t
                } else {
                    log.info("trace with id ${t.traceId} kept for the negative checks")
                }
            }
            awaited.addAll(got.values())
            got
        }

        // Sends two carriers to `path` (default CARRIER_PATH), the second once
        // the first's trace arrived, and waits for its trace: every trace that
        // the process sent before them has arrived then. It must come after
        // the request expected to take a pending startup event, which it
        // would otherwise take itself.
        void barrier(String path = Php2ShellHelpers.CARRIER_PATH) {
            2.times {
                CarrierReq b = Php2ShellHelpers.sendCarrier(c, [:], path)
                next([b.traceId])
            }
        }

        // Of the traces received so far, none other than those next()
        // returned has one of `ids`, and none has a startup span.
        void assertNotSent(Collection<BigInteger> ids) {
            c.drainCapturedTraces().each { record(it) }
            List<Trace> others = seen.findAll { !awaited.contains(it) }
            List<BigInteger> unexpected = others*.traceId.findAll { it in ids }
            assert !unexpected : "unexpected traces with ids ${unexpected}"
            List<Trace> startup = others.findAll { t -> t.any { Php2ShellHelpers.p2sTags(it).containsKey('deferred') } }
            assert !startup : "unexpected startup spans in traces ${startup*.traceId}"
        }

        private void record(Trace t) {
            assert !seen.any { it.traceId == t.traceId } : "two traces with id ${t.traceId}"
            seen << t
        }
    }

    // The trace of a startup span: one span with the failed request's data,
    // the deferred fatal as its error and the AppSec keep. `service` is the
    // service resolved for the failed request; `dropped`, the expected
    // startup_dropped tag (null: absent). `t0` (Unix epoch ns) was taken
    // before the failed request was sent, `carrier` is the carrier's trace
    // (null: the carrier sent none).
    // `tagKey` is the tag the carrier's header tags give TAG_HEADER (default
    // TAG_KEY). `process` is the root span of a request that ran in the
    // process that then failed, whose process id and runtime id the span must
    // report; `sameProcess` says whether the carrier ran in that process too
    // (null: not checked). `hostname` is the hostname the failed request
    // reported (null: it reported none).
    static void assertStartupSpan(Map opts, Trace trace, StartupReq sent) {
        assert trace.size() == 1
        Span s = trace.first()
        log.info("startup span: ${s.properties}")
        assert s.traceId == sent.traceId
        assert s.parentId == sent.parentId
        assert s.name == STARTUP_SPAN_NAME
        assert s.type == 'web'
        assert s.service == (opts.service ?: 'appsec_int_tests')
        assert s.meta.env == 'integration'
        assert p2sTags(s) == ([deferred: 'startup'] +
                (opts.dropped != null ? [startup_dropped: opts.dropped as String] : [:]))
        assert s.meta['http.method'] == 'POST'
        assert s.resource == 'POST /php2shell_sink.php'
        assert s.meta['http.url'].endsWith('/php2shell_sink.php?key1=val1&<redacted>')
        assert s.meta['http.status_code'] == '500'
        assert s.error
        assert s.meta['error.type'] == 'E_ERROR'
        assert s.meta['error.message'].contains(
                "Allowed memory size of ${STARTUP_LIMIT} bytes exhausted (${DEFERRED_SUFFIX}")
        assert s.meta['http.useragent'] == USER_AGENT
        assert s.meta['http.client_ip'] == CLIENT_IP
        assert s.meta[(opts.tagKey ?: TAG_KEY) as String] == 'v'
        // The AppSec keep overrides the incoming user reject.
        assert s.metrics._sampling_priority_v1 == 2.0d
        assert s.meta['_dd.p.dm'] == '-5'
        // The rest of the incoming context, not the carrier's.
        assert s.meta['_dd.origin'] == ORIGIN
        assert s.meta[PROPAGATED_TAG] == '1'
        // The saved start and duration, not the carrier's. The start is
        // compared with a second of slack for the clocks of the test host and
        // the container.
        long t0 = opts.t0 as long
        assert s.duration > 0
        assert s.start >= t0 - 1_000_000_000L
        if (opts.carrier != null) {
            assert s.start + s.duration <= (opts.carrier as Trace).rootSpan.start
        }
        // The identity of the failed process, not the carrier's.
        if (opts.process != null) {
            Span process = opts.process as Span
            assert s.metrics.process_id == process.metrics.process_id
            assert s.meta['runtime-id'] == process.meta['runtime-id']
        }
        if (opts.sameProcess != null) {
            Span carrier = (opts.carrier as Trace).rootSpan
            assert (s.metrics.process_id == carrier.metrics.process_id) == opts.sameProcess
            assert (s.meta['runtime-id'] == carrier.meta['runtime-id']) == opts.sameProcess
        }
        assert s.meta['_dd.hostname'] == opts.hostname
    }

    // Sends a carrier and returns the root span of its trace, which tells the
    // process that ran it.
    static Span sendProbe(AppSecContainer c) {
        CarrierReq probe = sendCarrier(c)
        c.nextCapturedTraces([probe.traceId])[probe.traceId].rootSpan
    }

    static void assertCarrier(Trace trace, CarrierReq sent) {
        assertCarrier([:], trace, sent)
    }

    // The carrier's own trace carries its own request's data and nothing of
    // the failed request. `tagKey` as for assertStartupSpan; `hostname`, the
    // hostname the carrier reported (null: none), not checked if
    // `checkHostname` is false; `path`, the path it was
    // sent to (default CARRIER_PATH); `appsec`, whether AppSec was enabled
    // for the carrier (absent: not checked).
    static void assertCarrier(Map opts, Trace trace, CarrierReq sent) {
        Span root = trace.rootSpan
        String path = (opts.path ?: CARRIER_PATH) as String
        assert trace.every { it.traceId == sent.traceId }
        assert root.parentId == sent.parentId
        assert root.name == ROOT_SPAN_NAME
        assert root.type == 'web'
        assert root.resource == "GET ${path}".toString()
        assert root.service == 'appsec_int_tests'
        assert root.meta['http.method'] == 'GET'
        assert root.meta['http.url'].endsWith(path)
        assert root.meta['http.status_code'] == '200'
        assert root.meta['http.useragent'] == CARRIER_USER_AGENT
        assert !root.error
        assert !trace.any { it.error }
        assert !root.meta.containsKey('error.type')
        assert p2sTags(root) == [:]
        String tagKey = (opts.tagKey ?: TAG_KEY) as String
        assert root.meta[tagKey] == 'c'
        assert root.meta['_dd.p.dm'] != '-5'
        assert root.metrics._sampling_priority_v1 != 2.0d
        if (opts.checkHostname != false) {
            assert root.meta['_dd.hostname'] == opts.hostname
        }
        assert !root.meta.containsKey('_dd.origin')
        assert !root.meta.containsKey(PROPAGATED_TAG)
        if (opts.containsKey('appsec')) {
            assert (root.metrics['_dd.appsec.enabled'] == 1.0d) == opts.appsec
        }
    }

    // The process id of the process that wrote the mitigation's warning for a
    // failed request startup, from the lines appended to `appsecLog` since
    // its mark, which must have exactly one. A line starts with
    // "[time][pid][level]", or "[time][pid:tid][level]" under ZTS.
    static long failedRequestPid(LogFile appsecLog) {
        List<String> lines = appsecLog.linesSinceMark.findAll {
            it.contains('raising deferred out-of-memory error at a safe point (phase:startup)')
        }
        assert lines.size() == 1 : "expected one deferred startup fatal in the AppSec log; got ${lines}"
        def m = lines[0] =~ /^\[[^\]]*\]\[(\d+)(?::\d+)?\]/
        assert m.find() : "no pid in ${lines[0]}"
        m.group(1) as long
    }

    // The span's _dd.appsec.php2shell.* tags, without the prefix.
    static Map<String, String> p2sTags(Span span) {
        span.meta
                .findAll { it.key.startsWith(TAG_PREFIX) }
                .collectEntries { [(it.key.substring(TAG_PREFIX.length())): it.value] } as Map<String, String>
    }

    // The headers of a failed request for assertStartupSpan, plus `extra`.
    static Map<String, String> startupHeaders(Map<String, String> extra = [:]) {
        ['User-Agent': USER_AGENT, 'X-Forwarded-For': CLIENT_IP, (TAG_HEADER): 'v'] + extra
    }

    // Writes STARTUP_CONF with STARTUP_CONF_LINES and `extraLines`.
    static void writeStartupConf(AppSecContainer c, List<String> extraLines = []) {
        String content = (STARTUP_CONF_LINES + extraLines).join('\n')
        def res = c.execInContainer('bash', '-c', "cat > ${STARTUP_CONF} <<'EOF'\n${content}\nEOF".toString())
        assert res.exitCode == 0 : res.stderr
    }

    static void removeStartupConf(AppSecContainer c) {
        c.execInContainer('rm', '-f', STARTUP_CONF)
    }

    // A service name no other case uses, for the failed requests of one case
    // (see SERVICE_HEADER). Their metric points are reported under the
    // service resolved for them, so no other case's points can reach it.
    static String uniqueService(String prefix) {
        "${prefix}-${Long.toHexString(RAND.nextLong() & Long.MAX_VALUE)}".toString()
    }

    // Ends the telemetry of the application of a request with `headers` (by
    // default the application service), which makes the sidecar send what
    // it holds for that application at once, followed by app-closing (see
    // flush_telemetry.php).
    static void finalizeTelemetry(AppSecContainer c, Map<String, String> headers = [:]) {
        def br = c.buildReq('/flush_telemetry.php').GET()
        headers.each { k, v -> br.setHeader(k, v) }
        HttpResponse<String> resp = c.httpClient.send(br.build(), ofString())
        assert resp.statusCode() == 200
    }

    // The sums of the points of `name` whose tags include all of `tags`,
    // reported under each of `services`: those of the failed requests of a
    // burst, one service per request, so that each service has the points
    // of one failed request only.
    //
    // A failed request hands its point to the sidecar over its process's
    // connection, with a send that is not acknowledged (on php-fpm, the
    // process then exits). The sidecar serves each connection in a task of
    // its own, so a later message sent over another connection is not
    // ordered after it. Once a point of a service has reached the agent,
    // though, the sidecar has processed the message that carried it, and
    // ending that service's telemetry afterwards (finalizeTelemetry) is
    // queued behind it on the service's telemetry client: the sidecar sends
    // what it holds for the service, then app-closing. So this waits until
    // every service has a point, then ends each one and sums the points up
    // to its app-closing.
    static Map<String, Double> metricSumsAtClose(AppSecContainer c, List<String> services, String name,
                                                 List<String> tags, long timeoutInMs = 20_000) {
        long deadline = System.currentTimeMillis() + timeoutInMs
        Map<String, Double> sums = services.collectEntries { [(it): 0d] } as Map<String, Double>
        Set<String> closed = []
        Closure drain = {
            assert System.currentTimeMillis() < deadline :
                    "metric ${name}: sums ${sums}, app-closing seen for ${closed} of ${services}"
            for (msg in c.drainTelemetry(500)) {
                String service = msg.application?.service_name
                if (!(service in services) || service in closed) {
                    continue
                }
                for (TelemetryHelpers.GenerateMetrics gm in
                        TelemetryHelpers.filterMessages([msg], TelemetryHelpers.GenerateMetrics, false)) {
                    for (Metric m in gm.series) {
                        if (m.name == name && m.tags.containsAll(tags)) {
                            sums[service] += m.points.sum { (it as List)[1] as double } as double
                        }
                    }
                }
                if (TelemetryHelpers.isAppClosing(msg as Map)) {
                    closed << service
                }
            }
        }
        while (sums.values().any { it < 1 }) {
            drain()
        }
        services.each { finalizeTelemetry(c, [(SERVICE_HEADER): it]) }
        while (closed.size() < services.size()) {
            drain()
        }
        sums
    }
}
