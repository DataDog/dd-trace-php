package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.integration.Php2ShellHelpers.StartupReq
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.Php2ShellHelpers.HEADER_TAGS
import static com.datadog.appsec.php.integration.Php2ShellHelpers.STARTUP_MEMORY_LIMIT
import static com.datadog.appsec.php.integration.Php2ShellHelpers.STARTUP_POST_MAX_SIZE
import static com.datadog.appsec.php.integration.Php2ShellHelpers.removeStartupConf
import static com.datadog.appsec.php.integration.Php2ShellHelpers.sendStartupReq
import static com.datadog.appsec.php.integration.Php2ShellHelpers.writeStartupConf
import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * The startup span of the PHP2Shell mitigation under Apache mod_php: the cases
 * of Php2ShellStartupSpanCases, which Php2ShellMitigationTests also runs on
 * php-fpm. A request whose body crosses memory_limit in the startup POST parse
 * fails its request startup and has no root span; the next traced request
 * sends its span as a trace of its own (see Php2ShellHelpers).
 *
 * A separate class because the mock agent's trace handler is shared by every
 * container: two containers in one class would see each other's traces.
 *
 * memory_limit and post_max_size are set with php_admin_value for the sink's
 * location, so they are in force before its body is parsed and the carrier
 * requests keep the defaults. The mitigation mode and the header tag go to
 * Apache's environment: the hooks are installed at module startup, from the
 * parent's environment. The client IP is not enabled for the tracer: the
 * startup span carries it anyway, as AppSec's traces do.
 *
 * Each case restarts Apache, which also gives it a new, empty slot. The
 * image runs a single child (prefork with one worker, or, for ZTS, the event
 * MPM with one process of three threads), but under the SSI loader prefork
 * runs up to 16 children (MaxRequestWorkers 16, see enable_extensions.sh).
 * All the children share the slot, so the requests of a case need not run in
 * one process. Unlike a php-fpm worker, a child survives the failed startup
 * and goes on serving requests.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
@TestMethodOrder(MethodOrderer.MethodName)
class Php2ShellStartupSpanModTests implements Php2ShellStartupSpanCases {
    static boolean disabled = !(phpVersion in ['7.0', '7.4', '8.3', '8.5'])

    @Container
    public static final AppSecContainer CONTAINER = new AppSecContainer(
            workVolume: this.name,
            baseTag: 'apache2-mod-php',
            phpVersion: phpVersion,
            phpVariant: variant,
            www: 'base',
    ).withEnv('DD_TELEMETRY_HEARTBEAT_INTERVAL', '1') // as in Php2ShellMitigationTests

    private static final String ENVVARS = '/etc/apache2/envvars'
    private static final List<String> SINK_CONF_LINES = [
            '<Location "/php2shell_sink.php">',
            "    php_admin_value memory_limit ${STARTUP_MEMORY_LIMIT}".toString(),
            "    php_admin_value post_max_size ${STARTUP_POST_MAX_SIZE}".toString(),
            '</Location>',
    ]
    private static final Map<String, String> APACHE_ENV = [
            DD_APPSEC_PHP2SHELL_MITIGATION: 'on',
            DD_TRACE_HEADER_TAGS: HEADER_TAGS,
    ]

    @BeforeEach
    void setUp() {
        writeStartupConf(CONTAINER, SINK_CONF_LINES)
        String exports = APACHE_ENV.collect { k, v -> "export ${k}=${v}" }.join('\n')
        def res = CONTAINER.execInContainer('bash', '-c',
                "cp ${ENVVARS} ${ENVVARS}.p2s && cat >> ${ENVVARS} <<'EOF'\n${exports}\nEOF".toString())
        assert res.exitCode == 0 : res.stderr
        CONTAINER.restartApache()
        CONTAINER.clearTraces()
    }

    @AfterEach
    void tearDown() {
        CONTAINER.execInContainer('bash', '-c', "mv -f ${ENVVARS}.p2s ${ENVVARS}".toString())
        removeStartupConf(CONTAINER)
        CONTAINER.restartApache()
    }

    @Override
    <T> T startupSpanCase(Closure<T> body) {
        // setUp and tearDown already prepare and restore Apache for each case.
        body()
    }

    // mod_php answers with the status the fatal set (500) and an empty body.
    @Override
    void sendFailed(StartupReq sr) {
        int status = sendStartupReq(CONTAINER, sr)
        assert status == 500 : "expected the failed request startup (500); got ${status}"
    }
}
