<?php

// A span carrying composite attributes under meta_struct roots (sent as meta_struct when
// downgrading to v0.4), next to look-alikes which are flattened into meta/metrics.
function meta_struct_attributes_span()
{
    $s = \DDTrace\start_span();
    $s->name = "root";
    $s->service = "meta-struct-svc";
    $s->attributes['_dd.stack'] = ['exploit' => [['id' => 'e1', 'frames' => [['line' => 13, 'file' => 'a.php']]]]];
    $s->attributes['api.security.schema'] = ['k' => [1, 'a']];
    // events is read from meta: it is flattened, the rest of appsec goes to meta_struct.
    $s->attributes['appsec'] = ['events' => ['u' => 'x'], 'triggers' => [['rule' => 'r']]];
    $s->attributes['appsec.fingerprint.http'] = ['h' => 'v'];
    // *.json keys stay as they are.
    $s->attributes['appsec.json'] = ['j' => 'y'];
    $s->attributes['iast.s'] = 'x';
    $s->attributes['apisecurity'] = ['x' => 1];
    // Deprecated and ignored.
    $s->meta_struct['ignored'] = [1];
    \DDTrace\close_span();
}

// Sorts map keys recursively, as the order of map members is not kept on the wire.
function meta_struct_attributes_sorted($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('meta_struct_attributes_sorted', $value);
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
    return $value;
}

function meta_struct_attributes_dump_v04($span)
{
    $meta_struct = meta_struct_attributes_sorted($span['meta_struct'] ?? []);
    foreach ($meta_struct as $key => $value) {
        echo "meta_struct.$key=", json_encode($value), "\n";
    }
    foreach (['meta', 'metrics'] as $bucket) {
        $tags = $span[$bucket] ?? [];
        ksort($tags);
        foreach ($tags as $key => $value) {
            if (preg_match('/^(_dd\.stack|api\.|appsec|iast|apisecurity|ignored)/', $key)) {
                echo "$bucket.$key=", json_encode($value), "\n";
            }
        }
    }
}
