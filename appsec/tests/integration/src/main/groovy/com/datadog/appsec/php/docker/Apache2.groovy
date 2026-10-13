package com.datadog.appsec.php.docker

import groovy.util.logging.Slf4j
import org.testcontainers.containers.Container.ExecResult

/**
 * Ungraceful restarts of the mod_php Apache inside a running {@link AppSecContainer}.
 *
 * Apache's just_die() calls exit() from the SIGTERM handler of a child that is still starting up,
 * so a restart can abort such a child mid-malloc ("free(): corrupted unsorted chunks").
 */
@Slf4j
class Apache2 {
    // Bounded (10 s) wait until there is a child and every child runs more than one thread, i.e. is
    // past start-up; then record the children in $OLD and SIGHUP right away. On timeout, no signal.
    private static final String SIGHUP_ONCE_CHILDREN_STARTED =
            'P=$(pgrep -o -f /usr/sbin/apache2) && i=0 && ' +
            'until T=$(ps -o nlwp= --ppid $P); [ -n "$T" ] && ! echo "$T" | grep -qx " *1"; do ' +
            'i=$((i + 1)); if [ $i -gt 200 ]; then ' +
            'echo "apache2 children not started after 10 s:" >&2; ' +
            'ps -o pid,nlwp,etime,stat,args --ppid $P >&2; exit 3; fi; ' +
            'sleep 0.05; done && ' +
            'OLD=$(pgrep -P $P) && kill -HUP $P && echo "waited_ms=$((i * 50))"'

    private final AppSecContainer container

    Apache2(AppSecContainer container) {
        this.container = container
    }

    /**
     * Ungraceful restart (SIGHUP to the parent): children are killed without PHP teardown.
     *
     * @param before shell run first, e.g. a config edit (validated with configtest before the HUP)
     * @param after shell run after the HUP; {@code $OLD} holds the pre-restart children
     */
    ExecResult ungracefulRestart(String before = 'true', String after = 'true') {
        ExecResult res = container.execInContainer('sh', '-c',
                "${before} && apache2ctl configtest && ${SIGHUP_ONCE_CHILDREN_STARTED} && ${after}".toString())
        assert res.exitCode == 0: "apache2 ungraceful restart failed (exit ${res.exitCode}): ${res.stderr}"
        log.info('apache2 SIGHUP sent ({})', res.stdout.find(/waited_ms=\d+/))
        res
    }
}
