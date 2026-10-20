<?php

// FNV-1 64 over $bytes, continuing from the 64-bit state split into 32-bit halves so that the
// multiplication never overflows a PHP int.
function dsm_fnv1_64(array $state, string $bytes): array {
    list($hi, $lo) = $state;
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        // h * (2^40 + 0x1b3) mod 2^64; of h * 2^40 only the low half survives, as lo << 8.
        $l = $lo * 0x1b3;
        $hi = ($hi * 0x1b3 + ($l >> 32) + ($lo << 8)) & 0xffffffff;
        $lo = ($l & 0xffffffff) ^ ord($bytes[$i]);
    }
    return [$hi, $lo];
}

// The pathway hash of a first checkpoint (parent 0) with these edge tags and process tags.
function dsm_expected_hash(string $service, string $env, array $edgeTags, array $processTags): string {
    $offset = [0xcbf29ce4, 0x84222325];
    sort($edgeTags);
    $node = $offset;
    foreach (array_merge([$service, $env], $edgeTags, $processTags) as $part) {
        $node = dsm_fnv1_64($node, $part);
    }
    $pathway = dsm_fnv1_64($offset, pack('VV', $node[1], $node[0]));
    $pathway = dsm_fnv1_64($pathway, pack('VV', 0, 0));
    return sprintf('%u', ($pathway[0] << 32) | $pathway[1]);
}

function dsm_context_hash(string $context): string {
    return sprintf('%u', unpack('P', substr(base64_decode($context, true), 0, 8))[1]);
}

// The process tags the tracer reports, as the root span carries them: the base process tags
// followed by the service-source tag (svc.user or svc.auto).
function dsm_process_tags(): array {
    \DDTrace\start_span();
    \DDTrace\close_span();
    $spans = dd_trace_serialize_closed_spans();
    return explode(',', $spans[0]['meta']['_dd.tags.process']);
}
