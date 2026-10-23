--TEST--
Data Streams Monitoring: the pathway hash covers the base hash DBM injects
--DESCRIPTION--
With process tags on, the node hash ends with the base hash from process_tags_base_hash(), which
covers the process tags and the container tags hash, rather than with the process tags themselves.
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_SERVICE=weblog
DD_ENV=system-tests
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

// FNV-1 64 over $bytes, continuing from the 64-bit state split into 32-bit halves so that the
// multiplication never overflows a PHP int.
function fnv1_64(array $state, string $bytes): array {
    list($hi, $lo) = $state;
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        // h * (2^40 + 0x1b3) mod 2^64; of h * 2^40 only the low half survives, as lo << 8.
        $l = $lo * 0x1b3;
        $hi = ($hi * 0x1b3 + ($l >> 32) + ($lo << 8)) & 0xffffffff;
        $lo = ($l & 0xffffffff) ^ ord($bytes[$i]);
    }
    return [$hi, $lo];
}

// The pathway hash of a first checkpoint (parent 0).
function expected_hash(array $parts): string {
    $offset = [0xcbf29ce4, 0x84222325];
    $node = $offset;
    foreach ($parts as $part) {
        $node = fnv1_64($node, $part);
    }
    $pathway = fnv1_64($offset, pack('VV', $node[1], $node[0]));
    $pathway = fnv1_64($pathway, pack('VV', 0, 0));
    return sprintf('%u', ($pathway[0] << 32) | $pathway[1]);
}

function context_hash(string $context): string {
    return sprintf('%u', unpack('P', substr(base64_decode($context, true), 0, 8))[1]);
}

$baseHash = \DDTrace\System\process_tags_base_hash();
echo "base hash known: ", var_export(is_string($baseHash) && $baseHash !== '', true), "\n";

$tags = ['direction:out', 'topic:dsm-system-tests-queue', 'type:kafka'];
// The helper reproduces the hash system-tests expects with process tags off.
echo "helper matches system-tests: ", var_export(expected_hash(array_merge(['weblog', 'system-tests'], $tags)) === '4463699290244539355', true), "\n";
$hash = context_hash(\DDTrace\Internal\dsm_set_checkpoint($tags));

echo "covers the base hash: ", var_export($hash === expected_hash(array_merge(['weblog', 'system-tests'], $tags, [$baseHash])), true), "\n";
// The hash system-tests expects with process tags off.
echo "differs from the hash without it: ", var_export($hash !== '4463699290244539355', true), "\n";

?>
--EXPECT--
base hash known: true
helper matches system-tests: true
covers the base hash: true
differs from the hash without it: true
