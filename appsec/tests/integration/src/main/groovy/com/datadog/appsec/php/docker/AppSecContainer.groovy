package com.datadog.appsec.php.docker

import com.datadog.appsec.php.mock_agent.MockDatadogAgent
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import com.github.dockerjava.api.command.CreateContainerCmd
import com.github.dockerjava.api.command.ExecCreateCmdResponse
import com.github.dockerjava.api.exception.NotFoundException
import com.github.dockerjava.api.model.Bind
import com.github.dockerjava.api.model.Volume
import com.google.common.util.concurrent.SettableFuture
import groovy.transform.CompileStatic
import groovy.transform.TypeCheckingMode
import groovy.transform.stc.ClosureParams
import groovy.transform.stc.FromAbstractTypeMethods
import groovy.transform.stc.FromString
import groovy.util.logging.Slf4j
import org.slf4j.Logger
import org.slf4j.LoggerFactory
import org.testcontainers.Testcontainers
import org.testcontainers.containers.BindMode
import org.testcontainers.containers.GenericContainer
import org.testcontainers.containers.output.FrameConsumerResultCallback
import org.testcontainers.containers.output.OutputFrame
import org.testcontainers.containers.output.Slf4jLogConsumer

import java.net.http.HttpClient
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration
import java.util.concurrent.Future
import java.util.function.Consumer

@CompileStatic
@Slf4j
class AppSecContainer<SELF extends AppSecContainer<SELF>> extends GenericContainer<SELF> {
    private final static Logger DOCKER_OUTPUT_LOGGER = LoggerFactory.getLogger('docker')
    private String imageName
    private Slf4jLogConsumer logConsumer
    private File logsDir
    public final HttpClient httpClient = HttpClient.newBuilder()
                    .followRedirects(HttpClient.Redirect.NEVER)
                    .connectTimeout(Duration.ofSeconds(5))
                    .build()

    private MockDatadogAgent mockDatadogAgent = new MockDatadogAgent()

    AppSecContainer(Map options) {
        super(imageNameFuture(options))
        processOptions(options)
        dependsOn mockDatadogAgent
        withCreateContainerCmdModifier(cmd -> {
            cmd.hostConfig.withInit(true)
        })
        withExposedPorts(80)
    }

    private static Future<String> imageNameFuture(Map options) {
        var ret = SettableFuture.create()
        options['imageNameFuture'] = ret
        (Future)ret
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
        withEnv 'DD_TRACE_LOG_LEVEL', 'info,startup=off'
        withEnv 'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS', '0'
        withEnv 'DD_TRACE_AGENT_FLUSH_INTERVAL', '0'
        withEnv 'DD_TRACE_DEBUG', '1'
        withEnv 'DD_AUTOLOAD_NO_COMPILE', 'true' // must be exactly 'true'
        withEnv 'DD_INSTRUMENTATION_TELEMETRY_ENABLED', '1'
        withEnv '_DD_DEBUG_SIDECAR_LOG_METHOD', 'file:///tmp/logs/sidecar.log'
        withEnv 'DD_TELEMETRY_HEARTBEAT_INTERVAL', '10'
        withEnv 'DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL', '10'
        withEnv '_DD_SHARED_LIB_DEBUG', '1'
        if (System.getProperty('XDEBUG') == '1') {
            Testcontainers.exposeHostPorts(9003)
            withEnv 'XDEBUG', '1'
            withEnv 'PHP_IDE_CONFIG', "serverName=appsec_int_tests"
        }
    }

    @Override
    protected void doStart() {
        super.doStart()
        this.logConsumer = new Slf4jLogConsumer(DOCKER_OUTPUT_LOGGER)
        followOutput(logConsumer)
        overlayWww()
        runInitialize()
    }

    Object nextCapturedTrace() {
        mockDatadogAgent.nextTrace(15000)
    }

    void assertNoTraces() {
        List<Object> traces = mockDatadogAgent.drainTraces()
        if (traces.size() > 0) {
            throw new AssertionError((Object)"Got traces that were not fetched: $traces")
        }
    }

    void clearTraces() {
        mockDatadogAgent.drainTraces()
    }

    List<Object> drainTelemetry(int timeoutInMs) {
        mockDatadogAgent.drainTelemetry(timeoutInMs)
    }

    void close() {
        copyLogs()
        super.close()
    }

    private static final Random RAND = new Random()

    URI buildURI(String path) {
        URI.create("http://${host}:${firstMappedPort}$path")
    }

    HttpRequest.Builder buildReq(String path) {
        BigInteger traceId = new BigInteger(64, RAND)

        HttpRequest.newBuilder(buildURI(path))
                .header('x-datadog-trace-id', traceId.toString())
    }

    Trace traceFromRequest(String path,
                           @ClosureParams(value = FromAbstractTypeMethods,
                                   options = ['java.net.http.HttpResponse'])
                                   Closure<Void> doWithConn = null) {
        HttpRequest req = buildReq(path).GET().build()
        traceFromRequest(req, HttpResponse.BodyHandlers.ofInputStream(), doWithConn)
    }

    @CompileStatic(TypeCheckingMode.SKIP)
    <T> Trace traceFromRequest(HttpRequest req,
                               HttpResponse.BodyHandler<T> bodyHandler,
                               @ClosureParams(value = FromString,
                                       options = 'java.net.http.HttpResponse<T>')
                                       Closure<Void> doWithResp = null) {

        String traceId = req.headers().map().get('x-datadog-trace-id').first()
        if (!traceId) {
            throw new IllegalArgumentException("use createReq to create the request")
        }

        log.info "New request to {} with trace id {}", req.uri(), traceId

        HttpResponse<Object> resp = httpClient.send(req, bodyHandler)
        Throwable savedThrowable
        try {
            if (doWithResp) {
                doWithResp.call(resp)
            }
        } catch (Throwable t) {
            // don't throw right now. Give an opportunity for a trace to be sent
            // so that this trace doesn't show up on the next test
            savedThrowable = t
        }

        if (resp.body() instanceof InputStream) {
            ((InputStream)resp.body()).close()
        }

        Trace trace
        if (savedThrowable) {
            try {
                nextCapturedTrace()
            } finally {
                throw savedThrowable
            }
        } else {
            trace = nextCapturedTrace()
        }
        assert trace.size() >= 1

        BigInteger gottenTraceId = trace.traceId
        BigInteger expectedTraceId = new BigInteger(traceId, 10)
        if (gottenTraceId != expectedTraceId) {
            throw new AssertionError("Mismatched trace id gotten after request to ${req.uri()}: " +
                    "expected ${expectedTraceId}, but got ${gottenTraceId}")
        }
        trace
    }

    private void processOptions(Map options) {
        String workVolume = options['workVolume']
        String baseTag = options['baseTag']
        String phpVersion = options['phpVersion']
        String phpVariant = options['phpVariant']

        if (!workVolume || !baseTag || !phpVersion || !phpVariant) {
            throw new RuntimeException('one of workVolume, baseTag, phpVersion, phpVariant is missing')
        }

        String tag = "$baseTag-$phpVersion-$phpVariant"
        this.imageName = "datadog/dd-appsec-php-ci:$tag"

        privilegedMode = true

        String wwwDir ="src/test/www/${options.get('www', 'base')}"

        withFileSystemBind('../../..', '/project', BindMode.READ_ONLY)
        withFileSystemBind(wwwDir, '/test-resources', BindMode.READ_ONLY)
        withFileSystemBind('src/test/waf/recommended.json',
                '/etc/recommended.json', BindMode.READ_ONLY)
        withFileSystemBind('src/test/resources/gdbinit', '/root/.gdbinit', BindMode.READ_ONLY)
        withFileSystemBind('src/test/bin/enable_extensions.sh',
                '/usr/local/bin/enable_extensions.sh', BindMode.READ_ONLY)
        addVolumeMount("php-appsec-$phpVersion-$phpVariant", '/appsec')
        addVolumeMount("php-tracer-$phpVersion-$phpVariant", '/project/tmp')

        String fullWorkVolume = "php-workvol-$workVolume-$phpVersion-$phpVariant"

        ensureVolume(fullWorkVolume)
        addVolumeMount(fullWorkVolume, '/overlay')

        ensureVolume('php-composer-cache')
        addVolumeMount('php-composer-cache', '/root/.composer/cache')

        addVolumeMount('php-tracer-cargo-cache', '/root/.cargo/registry')

        File composerFile
        if (phpVersion in ['7.0', '7.1']) {
            composerFile = new File('build/composer-2.2.x.phar')
        } else {
            composerFile = new File('build/composer-2.6.6.phar')
        }
        withFileSystemBind(composerFile.absolutePath, '/usr/local/bin/composer', BindMode.READ_ONLY)

        this.logsDir = new File("build/test-logs/$workVolume-$phpVersion-$phpVariant")

        if (new File(wwwDir, 'run.sh').exists()) {
            withCreateContainerCmdModifier { it.withEntrypoint('/bin/bash') }
            withCommand '-e', '-c', 'while [[ ! -f /var/www/run.sh ]]; do sleep 0.3; done; /var/www/run.sh'
        }

        ((SettableFuture)options['imageNameFuture']).set(this.imageName)
    }

    private void ensureVolume(String volumeName) {
        try {
            dockerClient.inspectVolumeCmd(volumeName).exec()
        } catch (NotFoundException e) {
            dockerClient.createVolumeCmd().withName(volumeName).exec()
        }
    }

    private void addVolumeMount(String volumeName, String mountPoint) {
        Consumer<CreateContainerCmd> volumeModifier = { CreateContainerCmd cmd ->
            Bind[] binds = cmd.hostConfig.binds
            Bind[] newBinds = new Bind[binds.length + 1]
            System.arraycopy(binds, 0, newBinds, 0, binds.length)
            newBinds[binds.length] = new Bind(volumeName, new Volume(mountPoint))
            cmd.hostConfig.withBinds(newBinds)
        }
        withCreateContainerCmdModifier(volumeModifier)
    }

    private void overlayWww() {
        ExecResult res = execInContainer('mkdir', '-p', '/var/www', '/overlay/www_upperdir', '/overlay/www_workdir')
        if (res.exitCode != 0) {
            throw new RuntimeException('failed creating directories: ' + res.stderr)
        }

        res = execInContainer('mount', '-t', 'overlay', '-o',
                'lowerdir=/test-resources,upperdir=/overlay/www_upperdir,workdir=/overlay/www_workdir',
                'overlay', '/var/www')
        if (res.exitCode != 0) {
            throw new RuntimeException('failed mounting overlay: ' + res.stderr)
        }
    }

    private void runInitialize() {
        execInContainerWithOutput('/bin/bash', '-c', '''
            if [[ -f /var/www/initialize.sh ]]; then
                /var/www/initialize.sh
            fi
        ''')
    }

    private void execInContainerWithOutput(String... cmd) {
        ExecCreateCmdResponse exec = dockerClient.execCreateCmd(containerId)
                .withAttachStdout(true).withAttachStderr(true).withCmd(cmd).exec()

        try (FrameConsumerResultCallback callback = new FrameConsumerResultCallback()) {
            callback.addConsumer(OutputFrame.OutputType.STDOUT, logConsumer)
            callback.addConsumer(OutputFrame.OutputType.STDERR, logConsumer)

            dockerClient.execStartCmd(exec.id).exec(callback).awaitCompletion()
        }

        Long exitCode = dockerClient.inspectExecCmd(exec.id).exec().exitCodeLong

        if (exitCode != 0) {
            throw new RuntimeException("Failed to execute command ${cmd.join(' ')}")
        }
    }

    private void copyLogs() {
        ExecResult res = execInContainer('find', '/tmp/logs', '-type', 'f')
        if (res.exitCode != 0) {
            log.error("Could not find logs to copy: $res.stderr")
            return
        }

        logsDir.mkdirs()

        res.stdout.eachLine {
            it = it.trim()
            if (it) {
                copyFileFromContainer(it, new File(logsDir, new File(it).name).absolutePath)
            }
        }
    }
}
