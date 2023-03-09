package com.datadog.appsec.php.docker

import com.datadog.appsec.php.webserver.MockDatadogAgent
import com.github.dockerjava.api.exception.NotFoundException
import com.google.common.util.concurrent.SettableFuture
import groovy.transform.CompileStatic
import groovy.transform.TypeCheckingMode
import groovy.transform.stc.ClosureParams
import groovy.transform.stc.FromAbstractTypeMethods
import groovy.util.logging.Slf4j
import org.slf4j.Logger
import org.slf4j.LoggerFactory
import org.testcontainers.Testcontainers
import org.testcontainers.containers.GenericContainer
import org.testcontainers.containers.output.Slf4jLogConsumer
import org.testcontainers.lifecycle.Startable

import java.util.concurrent.Future
import java.util.concurrent.TimeUnit

@CompileStatic
@Slf4j
class AppSecContainer<SELF extends AppSecContainer<SELF>> extends GenericContainer<SELF> {
    private final static Logger DOCKER_OUTPUT_LOGGER = LoggerFactory.getLogger('docker')
    private String imageDir
    private String imageName
    private List<String> buildArgs

    private MockDatadogAgent mockDatadogAgent = new MockDatadogAgent()

    AppSecContainer(Map options) {
        super(imageNameFuture(options))
        processOptions(options)
        dependsOn(new CreateImageStartable(), mockDatadogAgent)
        withExposedPorts(80)
    }

    private static Future<String> imageNameFuture(Map options) {
        var ret = SettableFuture.create();
        options['imageNameFuture'] = ret;
        ret
    }

    void close() {
        super.close();
    }

    @Override
    protected void configure() {
        Testcontainers.exposeHostPorts(mockDatadogAgent.port)
        withEnv 'DD_AGENT_HOST', 'host.testcontainers.internal'
        withEnv 'DD_TRACE_AGENT_PORT', mockDatadogAgent.port as String
        withEnv 'DD_TRACE_GENERATE_ROOT_SPAN', '1'
        withEnv 'DD_TRACE_ENABLED', '1'
        withEnv 'DD_SERVICE', 'appsec_int_tests'
        withEnv 'DD_ENV', 'integration'
        withEnv 'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS', '0'
        withEnv 'DD_TRACE_DEBUG', '1'
    }

    @Override
    protected void doStart() {
        super.doStart()
        Slf4jLogConsumer logConsumer = new Slf4jLogConsumer(DOCKER_OUTPUT_LOGGER)
        followOutput(logConsumer)
    }

    Object nextCapturedTrace() {
        mockDatadogAgent.nextTrace(15000)
    }

    void assertNoTraces() {
        List<Object> traces = mockDatadogAgent.drainTraces()
        if (traces.size() > 0) {
            throw new AssertionError("Got traces that were not fetched: $traces")
        }
    }

    void clearTraces() {
        mockDatadogAgent.drainTraces()
    }

    private static final Random RAND = new Random()

    HttpURLConnection createRequest(String uri) {
        def conn = new URL("http://${host}:${firstMappedPort}$uri").openConnection() as HttpURLConnection
        conn.useCaches = false
        conn
    }

    @CompileStatic(TypeCheckingMode.SKIP)
    Object traceFromRequest(String uri,
                            @ClosureParams(value = FromAbstractTypeMethods,
                                    options = ['java.net.HttpURLConnection'])
                                    Closure<Void> doWithConn = null) {
        BigInteger traceId = new BigInteger(64, RAND)
        HttpURLConnection conn = createRequest(uri)
        conn.useCaches = false
        conn.addRequestProperty('x-datadog-trace-id', traceId as String)
        if (doWithConn) {
            doWithConn.call(conn)
        }
        if (conn.doOutput) {
            conn.outputStream.close()
        }
        (conn.errorStream ?: conn.inputStream).close()

        Object trace = nextCapturedTrace()
        assert trace.size() == 1 && trace[0].size() == 1
        trace = trace[0][0]

        def gottenTraceId = ((Map)trace).get('trace_id')
        if (gottenTraceId != traceId) {
            throw new AssertionError("Mismatched trace id gotten after request to $uri: " +
                    "expected $traceId, but got $gottenTraceId")
        }
        trace
    }

    private void processOptions(Map options) {
        String phpVersion = options['phpVersion']
        String phpVariant = options['phpVariant']
        String tracerVersion = options['tracerVersion']
        this.buildArgs = [phpVersion, phpVariant, tracerVersion]

        this.imageDir = options['imageDir']
        String tag = "$phpVersion-$phpVariant-tracer$tracerVersion"
        this.imageName = "dd-appsec-php-$imageDir:$tag"

        ((SettableFuture)options['imageNameFuture']).set(this.imageName)
    }

    private void ensureImageExists() {
        log.debug("Checking if image ${this.imageName} exists")
        try {
            dockerClient.inspectImageCmd(this.imageName).exec()
            log.debug("Image ${this.imageName} already exists")
            return
        } catch (NotFoundException nfe) {
            log.info("Image ${this.imageName} does not exist")
        }

        // not found
        def cmd = ["../docker/$imageDir/build_image.sh" as String]
        cmd.addAll(this.buildArgs)
        log.info("Running $cmd");
        Process p = cmd.execute()
        Thread.start {
            p.errorStream.transferTo(System.err)
        }
        Thread.start {
            p.inputStream.transferTo(System.out)
        }
        boolean ended = p.waitFor(45, TimeUnit.MINUTES)
        if (!ended) {
            p.destroyForcibly()
            throw new RuntimeException("Process $cmd killed after timeout")
        }

        if (p.exitValue() != 0) {
            throw new RuntimeException("Process $cmd exited with ${p.exitValue()}")
        }

        try {
            dockerClient.inspectImageCmd(this.imageName).exec()
        } catch (NotFoundException nfe) {
            throw new RuntimeException("Process $cmd did not create image $image")
        }
    }

    class CreateImageStartable implements Startable {
        @Override
        void start() {
            ensureImageExists()
        }

        @Override
        void stop() {
            // noop
        }
    }
}
