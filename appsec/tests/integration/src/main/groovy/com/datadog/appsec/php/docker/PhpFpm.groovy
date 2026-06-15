package com.datadog.appsec.php.docker

import groovy.util.logging.Slf4j
import org.testcontainers.containers.Container.ExecResult

/**
 * Helpers for reconfiguring PHP-FPM inside a running {@link AppSecContainer}.
 *
 * Two strategies are offered:
 * <ul>
 *   <li>{@link #restart} hard-kills every php-fpm process and relaunches the
 *       master, optionally exporting extra environment variables or loading a
 *       different php.ini. Use it for changes that only take effect on a cold
 *       start (environment variables, php.ini edits).</li>
 *   <li>{@link #reload} sends SIGUSR2 for a graceful reload and blocks until the
 *       pre-reload workers have been replaced. Use it for pool.d settings (e.g.
 *       {@code pm.max_children}) that FPM re-reads on USR2; pair it with
 *       {@link #setPoolValue} / {@link #backupPoolConfig} / {@link #restorePoolConfig}.</li>
 * </ul>
 */
@Slf4j
class PhpFpm {
    static final String FPM_CONF = '/etc/php-fpm.conf'
    static final String DEFAULT_INI = '/etc/php/php.ini'
    static final String POOL_CONF = '/etc/php-fpm.d/www.conf'

    private final AppSecContainer container

    PhpFpm(AppSecContainer container) {
        this.container = container
    }

    /**
     * Hard-restart php-fpm: kill every php-fpm process and relaunch the master.
     *
     * @param env extra environment variables exported before launch
     * @param iniPath the php.ini to load (defaults to {@link #DEFAULT_INI})
     */
    ExecResult restart(Map<String, String> env = [:], String iniPath = DEFAULT_INI) {
        container.flushProfilingData()
        String exports = env.collect { k, v -> "export ${k}=${v};" }.join(' ')
        ExecResult res = container.execInContainer('bash', '-c',
                "kill -9 `pgrep php-fpm`; ${exports} php-fpm -y ${FPM_CONF} -c ${iniPath}".toString())
        assert res.exitCode == 0 : "php-fpm restart failed: ${res.stderr}"
        res
    }

    /** Rewrite a single pool directive (e.g. {@code pm.max_children}) in place. */
    void setPoolValue(String key, String value, String poolConf = POOL_CONF) {
        container.execInContainer('sed', '-i', "s/${key} = .*/${key} = ${value}/".toString(), poolConf)
    }

    /** Back up the pool config so {@link #restorePoolConfig} can revert any edits. */
    void backupPoolConfig(String poolConf = POOL_CONF) {
        container.execInContainer('cp', poolConf, "${poolConf}.bak".toString())
    }

    /** Restore the pool config saved by {@link #backupPoolConfig}. */
    void restorePoolConfig(String poolConf = POOL_CONF) {
        container.execInContainer('mv', "${poolConf}.bak".toString(), poolConf)
    }

    /**
     * Gracefully reload the FPM master (re-reads pool config without dropping the
     * socket) and block until every pre-reload worker has been replaced by a
     * freshly-spawned one.
     */
    void reload(long timeoutMillis = 5_000) {
        List<String> old = workerPids()
        // Locate the master via its pid file, falling back to the oldest php-fpm process.
        container.execInContainer('bash', '-c',
                'kill -USR2 $(cat /run/php-fpm*.pid /var/run/php-fpm*.pid 2>/dev/null | head -1) ' +
                '2>/dev/null || pkill -USR2 -o php-fpm || true')
        waitForWorkerTurnover(old, timeoutMillis)
    }

    /** PIDs of the current pool worker processes (the master is excluded). */
    List<String> workerPids() {
        container.execInContainer('bash', '-c', "pgrep -f 'php-fpm: pool' || true")
                .stdout.readLines()*.trim().findAll { it }
    }

    private void waitForWorkerTurnover(List<String> old, long timeoutMillis) {
        long deadline = System.currentTimeMillis() + timeoutMillis
        while (true) {
            List<String> current = workerPids()
            if (!current.isEmpty() && current.intersect(old).isEmpty()) {
                return
            }
            if (System.currentTimeMillis() > deadline) {
                throw new IllegalStateException(
                        "php-fpm workers were not reloaded in time (old=${old}, current=${current})".toString())
            }
            Thread.sleep(100)
        }
    }
}
