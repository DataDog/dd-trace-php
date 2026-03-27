--TEST--
Sample rate is changed to 0 after first call during a minute when STANDALONE ASM is enabled and no asm events
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_APM_TRACING_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_samplingc
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Create all 3 spans before any network I/O so that the standalone ASM limiter's
// tick() calls (which happen during close_span()) all occur within milliseconds of
// each other, guaranteed to be in the same 60-second bucket regardless of how slow
// the request-replayer polling is (e.g. under Valgrind).
//
// The standalone limiter uses zend_hrtime()/60_000_000_000 (60-second buckets):
//   - Span 1: last_hit=0 at process start, timeval>0 → tick() returns true  → sampling=1
//   - Span 2: same bucket → tick() returns false → sampling=0
//   - Span 3: same bucket → tick() returns false → sampling=0
\DDTrace\start_span();
\DDTrace\close_span();

\DDTrace\start_span();
\DDTrace\close_span();

\DDTrace\start_span();
\DDTrace\close_span();

// Flush all 3 spans in one writer cycle
// 5000ms timeout ensures the background sender writer cycle completes even under Valgrind
dd_trace_internal_fn("synchronous_flush", 5000);

// Collect samplings. The 3 traces may arrive batched in one HTTP request or in
// separate requests depending on timing, so loop until we have 3 values.
$samplings = [];
$maxAttempts = 10;

for ($attempt = 0; $attempt < $maxAttempts && count($samplings) < 3; $attempt++) {
	try {
		$request = $rr->waitForDataAndReplay();
	} catch (Exception $e) {
		continue;
	}
	$body = $request["body"] ?? null;
	if ($body === null) {
		continue;
	}
	$root = json_decode($body, true);
	if (!is_array($root)) {
		continue;
	}
	if (isset($root["chunks"])) {
		foreach ($root["chunks"] as $chunk) {
			$spans = $chunk["spans"] ?? [];
			if (isset($spans[0]["metrics"]["_sampling_priority_v1"])) {
				$samplings[] = (int)$spans[0]["metrics"]["_sampling_priority_v1"];
			}
		}
	} else {
		foreach ($root as $trace) {
			if (is_array($trace) && isset($trace[0]["metrics"]["_sampling_priority_v1"])) {
				$samplings[] = (int)$trace[0]["metrics"]["_sampling_priority_v1"];
			}
		}
	}
}

if (count($samplings) === 3 && $samplings[0] === 1 && $samplings[1] === 0 && $samplings[2] === 0) {
	echo "All good" . PHP_EOL;
} else {
	echo "Got " . count($samplings) . " samplings: " . implode(", ", $samplings) . PHP_EOL;
	echo "Attempts: $attempt" . PHP_EOL;
}

echo "Done" . PHP_EOL;

?>
--EXPECTF--
All good
Done
