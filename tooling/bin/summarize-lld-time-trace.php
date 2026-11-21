<?php
if ($argc !== 2) {
    fwrite(STDERR, "usage: summarize-lld-time-trace.php <trace-dir>\n");
    exit(1);
}
$best = null;
foreach (glob($argv[1] . '/*.json') ?: [] as $file) {
    $events = array_values(array_filter(
        json_decode(file_get_contents($file), true)['traceEvents'] ?? [],
        function ($e) { return ($e['ph'] ?? '') === 'X' && isset($e['dur']) && strpos($e['name'], 'Total ') !== 0; }
    ));
    if (!$events) {
        continue;
    }
    $start = min(array_column($events, 'ts'));
    $end = max(array_map(function ($e) { return $e['ts'] + $e['dur']; }, $events));
    if ($best === null || $end - $start > $best['wall']) {
        $best = ['file' => $file, 'events' => $events, 'start' => $start, 'wall' => $end - $start];
    }
}
if ($best === null) {
    exit("lld time trace: no traces in {$argv[1]}\n");
}
printf("lld time trace: %s, wall %.1f s\n", basename($best['file']), $best['wall'] / 1e6);
$byName = [];
foreach ($best['events'] as $e) {
    $n = $e['name'];
    $b = $byName[$n] ?? ['count' => 0, 'sum' => 0, 'first' => PHP_INT_MAX, 'last' => 0, 'tids' => []];
    $b['count']++;
    $b['sum'] += $e['dur'];
    $b['first'] = min($b['first'], $e['ts']);
    $b['last'] = max($b['last'], $e['ts'] + $e['dur']);
    $b['tids'][$e['tid'] ?? 0] = true;
    $byName[$n] = $b;
}
uasort($byName, function ($a, $b) { return $b['sum'] <=> $a['sum']; });
printf("%-40s %6s %10s %10s %10s %7s\n", 'phase', 'count', 'cpu s', 'from s', 'to s', 'threads');
foreach (array_slice($byName, 0, 25, true) as $n => $b) {
    printf("%-40s %6d %10.1f %10.1f %10.1f %7d\n", substr($n, 0, 40), $b['count'], $b['sum'] / 1e6,
        ($b['first'] - $best['start']) / 1e6, ($b['last'] - $best['start']) / 1e6, count($b['tids']));
}
