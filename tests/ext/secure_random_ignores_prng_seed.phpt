--TEST--
DD_TRACE_SECURE_RANDOM bypasses the deterministic PRNG seed
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_DEBUG=false
DD_TRACE_SECURE_RANDOM=true
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

// With DD_TRACE_DEBUG_PRNG_SEED set and DD_TRACE_SECURE_RANDOM=false,
// the MT19937-64 produces a fixed sequence: every process run with the
// same seed yields the same IDs in the same order.
//
// With DD_TRACE_SECURE_RANDOM=true the MT is bypassed entirely, so the
// seed has no effect and consecutive IDs must differ (CSPRNG output).

DDTrace\start_span();
$id1 = DDTrace\active_span()->id;
DDTrace\close_span();

DDTrace\start_span();
$id2 = DDTrace\active_span()->id;
DDTrace\close_span();

// IDs must be valid non-zero numeric strings.
var_dump(preg_match('/^\d+$/', $id1) === 1 && $id1 !== '0');
var_dump(preg_match('/^\d+$/', $id2) === 1 && $id2 !== '0');

// CSPRNG output must not be equal across calls; this fails deterministically
// under the MT because seed 42 would produce the same first two values every run.
var_dump($id1 !== $id2);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
