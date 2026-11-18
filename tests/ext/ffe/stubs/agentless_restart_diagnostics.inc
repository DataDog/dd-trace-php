<?php

// Diagnostics for the agentless_sidecar_restart flake (CI job 2269681457).
// Silent on success; on failure they go to stderr (the PHPT diff) and to
// a .diag.txt next to the test's own sidecar log, under the CI artifacts.
final class FfeRestartDiagnostics
{
    private static $port;
    private static $log;
    private static $events = array();
    private static $samples = array();
    private static $lastSample = 0.0;
    private static $snapshot = null;
    private static $nextSnapshot = 0.0;
    private static $states = array('01' => 'EST', '02' => 'SYN_SENT', '03' => 'SYN_RECV', '04' => 'FIN_WAIT1',
        '05' => 'FIN_WAIT2', '06' => 'TIME_WAIT', '07' => 'CLOSE', '08' => 'CLOSE_WAIT', '09' => 'LAST_ACK',
        '0A' => 'LISTEN', '0B' => 'CLOSING');

    public static function start($address)
    {
        self::$port = (int) substr(strrchr($address, ':'), 1);
        $method = (string) getenv('_DD_DEBUG_SIDECAR_LOG_METHOD');
        $dir = (strpos($method, 'file://') === 0 ? dirname(substr($method, 7)) : sys_get_temp_dir()) . '/ffe-restart';
        if (is_dir($dir) || @mkdir($dir, 0777, true)) {
            self::$log = $dir . '/' . basename($_SERVER['SCRIPT_FILENAME'], '.php') . '-' . getmypid() . '.sidecar.log';
            // Inherited by the child, so only its dedicated sidecars log here.
            putenv('_DD_DEBUG_SIDECAR_LOG_METHOD=file://' . self::$log);
        }
        self::event('start port=' . self::$port . ' sidecar_log=' . self::$log);
    }

    public static function event($message)
    {
        self::$events[] = self::now() . ' ' . $message;
    }

    public static function sampleEvery($seconds)
    {
        if (microtime(true) - self::$lastSample >= $seconds) self::sample();
    }

    public static function sample()
    {
        self::$lastSample = microtime(true);
        $tcp = @file_get_contents('/proc/net/tcp');
        if ($tcp === false) return;
        // Listener accept queue, sockets of the fixture port (server and
        // client side), and the OTLP sockets of the default :4318 endpoint.
        preg_match_all('/^\s*\d+: [0-9A-F]{8}:([0-9A-F]{4}) [0-9A-F]{8}:([0-9A-F]{4}) ([0-9A-F]{2}) [0-9A-F]{8}:([0-9A-F]{8})/m', $tcp, $rows, PREG_SET_ORDER);
        $port = sprintf('%04X', self::$port);
        $queue = '?';
        $server = $client = $otlp = array();
        foreach ($rows as $row) {
            $state = isset(self::$states[$row[3]]) ? self::$states[$row[3]] : $row[3];
            if ($row[1] === $port) {
                if ($row[3] === '0A') $queue = hexdec($row[4]);
                else $server[$state] = (isset($server[$state]) ? $server[$state] : 0) + 1;
            }
            if ($row[2] === $port) $client[$state] = (isset($client[$state]) ? $client[$state] : 0) + 1;
            if ($row[2] === '10DE') $otlp[$state] = (isset($otlp[$state]) ? $otlp[$state] : 0) + 1;
        }
        self::$samples[] = sprintf('%s accept_queue=%s server{%s} client{%s} otlp4318{%s}', self::now(), $queue,
            self::histogram($server), self::histogram($client), self::histogram($otlp));
    }

    // Thread states and descriptors of the replacement sidecar while the
    // child is still waiting for the refreshed configuration. Locating it
    // scans /proc, so retry at most every 10 seconds until it is found.
    public static function maybeSnapshotSidecar($childPid, $previousPid)
    {
        if (self::$snapshot !== null || microtime(true) < self::$nextSnapshot) return;
        self::$nextSnapshot = microtime(true) + 10;
        $pid = ffeTestWaitForSidecarPid($childPid, $previousPid, 1);
        if ($pid === null) {
            self::event('replacement sidecar not found yet');
            return;
        }
        self::$snapshot = array(self::now() . " sidecar pid=$pid");
        $fds = @scandir("/proc/$pid/fd");
        $limits = (string) @file_get_contents("/proc/$pid/limits");
        preg_match('/^Max open files\s+(\S+)/m', $limits, $limit);
        $status = (string) @file_get_contents("/proc/$pid/status");
        preg_match('/^VmRSS:\s+(.*)$/m', $status, $rss);
        self::$snapshot[] = sprintf('fds=%s max_open_files=%s rss=%s', $fds ? count($fds) - 2 : '?',
            isset($limit[1]) ? $limit[1] : '?', isset($rss[1]) ? trim($rss[1]) : '?');
        $threads = array();
        foreach (glob("/proc/$pid/task/*", GLOB_ONLYDIR) ?: array() as $task) {
            $stat = (string) @file_get_contents("$task/stat");
            $wchan = trim((string) @file_get_contents("$task/wchan"));
            if (preg_match('/\((.*)\) (\S)/', $stat, $m)) {
                $key = $m[1] . ' ' . $m[2] . ' ' . ($wchan === '' ? '-' : $wchan);
                $threads[$key] = (isset($threads[$key]) ? $threads[$key] : 0) + 1;
            }
        }
        foreach ($threads as $key => $count) self::$snapshot[] = "  thread $key x$count";
    }

    public static function fail($error)
    {
        self::sample();
        self::event('failure ' . strtok($error->getMessage(), "\n"));
        if (getenv('USE_ZEND_ALLOC') === '0') {
            // Memcheck traces children and reports ss itself as a leak: list the
            // raw rows instead (a LISTEN row's rx_queue is the accept queue).
            $port = sprintf(':%04X ', self::$port);
            $ss = "/proc/net/tcp rows for the fixture port (ss skipped under valgrind):\n";
            foreach (@file('/proc/net/tcp') ?: array() as $row) {
                if (strpos($row, $port) !== false) $ss .= $row;
            }
        } else {
            $ss = @shell_exec("ss -tan '( sport = :" . self::$port . " or dport = :" . self::$port . " )' 2>&1; ss -ltn 'sport = :" . self::$port . "' 2>&1");
        }
        $dump = "=== ffe restart diagnostics ===\n" . implode("\n", self::$events) . "\n--- tcp samples\n"
            . implode("\n", self::$samples) . "\n--- replacement sidecar\n"
            . (self::$snapshot === null ? 'not taken' : implode("\n", self::$snapshot)) . "\n--- ss\n" . $ss
            . "=== end ffe restart diagnostics ===\n";
        if (self::$log) @file_put_contents(substr(self::$log, 0, -strlen('.sidecar.log')) . '.diag.txt', $dump);
        fwrite(STDERR, $dump);
    }

    public static function succeed()
    {
        // Keep artifacts for failures only.
        if (self::$log) @unlink(self::$log);
    }

    private static function histogram($counts)
    {
        $parts = array();
        foreach ($counts as $state => $count) $parts[] = "$state:$count";
        return implode(',', $parts);
    }

    private static function now()
    {
        $now = microtime(true);
        return gmdate('H:i:s', (int) $now) . sprintf('.%03d', ($now - floor($now)) * 1000);
    }
}
