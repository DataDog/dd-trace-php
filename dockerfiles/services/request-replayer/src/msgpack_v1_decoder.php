<?php

/**
 * Decoder for the v1 (`/v1.0/traces`) msgpack wire format.
 *
 * The v1 wire (see libdatadog `libdd-trace-utils/src/msgpack_{encoder,decoder}/v1/`) differs from
 * v0.4 in three ways this decoder undoes so the PHPUnit tests keep reading the canonical v0.4-shaped
 * per-span view:
 *   - Map keys are integer proto field numbers, not strings.
 *   - Strings use a streaming intern table: a value is either an inline msgpack `str` (which also
 *     appends to the table) or a msgpack `uint` index into it (index 0 == empty string).
 *   - Values in the unified `attributes` map are AnyValue-typed: [type_uint8, value] where type is
 *     String(1)/Bool(2)/Double(3)/Int64(4)/Bytes(5)/Array(6)/KeyValueList(7).
 *
 * It normalizes a v1 payload back to `{"chunks":[{"spans":[ <v0.4 span> ]}]}` (the shape
 * TracerTestTrait::parseRawDumpedTraces07 reads), un-promoting:
 *   - span env/version/component -> meta; span kind (uint) -> meta['span.kind'] (Internal/1 dropped,
 *     matching v0.4 where an unset span.kind produces no meta entry);
 *   - chunk 128-bit trace_id -> per-span trace_id (low 64 bits, decimal) + meta['_dd.p.tid'] (high 64
 *     bits, hex) on the local-root span; chunk origin -> meta['_dd.origin']; chunk sampling_mechanism
 *     -> meta['_dd.p.dm'] (v0.4 "-N" form); chunk sampling_priority -> metrics['_sampling_priority_v1'];
 *   - the unified attributes map back into meta (String), metrics (Int/Double) and meta_struct (Bytes);
 *   - native span_links/span_events back into the meta['_dd.span_links'] / meta['events'] JSON strings
 *     the v0.4 wire carried.
 */
class V1TraceDecoder
{
    private $buf;
    private $pos = 0;
    private $len;
    /** @var string[] streaming intern table; index 0 is the empty string */
    private $table = [''];

    // Integer map keys, kept in sync with the libdatadog v1 encoder/decoder.
    const TRACE_ATTRIBUTES = 10, TRACE_CHUNKS = 11;
    const CHUNK_PRIORITY = 1, CHUNK_ORIGIN = 2, CHUNK_ATTRIBUTES = 3, CHUNK_SPANS = 4,
          CHUNK_DROPPED_TRACE = 5, CHUNK_TRACE_ID = 6, CHUNK_SAMPLING_MECHANISM = 7;
    const SPAN_SERVICE = 1, SPAN_NAME = 2, SPAN_RESOURCE = 3, SPAN_SPAN_ID = 4, SPAN_PARENT_ID = 5,
          SPAN_START = 6, SPAN_DURATION = 7, SPAN_ERROR = 8, SPAN_ATTRIBUTES = 9, SPAN_TYPE = 10,
          SPAN_LINKS = 11, SPAN_EVENTS = 12, SPAN_ENV = 13, SPAN_VERSION = 14, SPAN_COMPONENT = 15,
          SPAN_KIND = 16;
    const LINK_TRACE_ID = 1, LINK_SPAN_ID = 2, LINK_ATTRIBUTES = 3, LINK_TRACE_STATE = 4, LINK_FLAGS = 5;
    const EVENT_TIME = 1, EVENT_NAME = 2, EVENT_ATTRIBUTES = 3;
    const ANY_STRING = 1, ANY_BOOL = 2, ANY_DOUBLE = 3, ANY_INT64 = 4, ANY_BYTES = 5, ANY_ARRAY = 6,
          ANY_KEY_VALUE_LIST = 7;

    public function __construct($buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    /** Decodes the whole payload into the v0.4-shaped `{"chunks":[...]}` PHP array. */
    public function decode()
    {
        $chunks = [];
        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            switch ($key) {
                case self::TRACE_CHUNKS:
                    $count = $this->readArrayLen();
                    for ($c = 0; $c < $count; $c++) {
                        $chunks[] = $this->decodeChunk();
                    }
                    break;
                case self::TRACE_ATTRIBUTES:
                    // Payload-level attributes (e.g. _dd.apm_mode) are not part of the per-span view.
                    $this->readAttributesMap();
                    break;
                default:
                    // container_id/language/version/runtime_id/env/hostname/app_version and any
                    // future/unknown key: interned string or arbitrary value, skip it.
                    $this->skipValue();
                    break;
            }
        }
        return ['chunks' => $chunks];
    }

    private function decodeChunk()
    {
        $traceIdBytes = null;
        $origin = null;
        $priority = null;
        $samplingMechanism = null;
        $spans = [];

        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            switch ($key) {
                case self::CHUNK_TRACE_ID:
                    $traceIdBytes = $this->readBin();
                    break;
                case self::CHUNK_SPANS:
                    $count = $this->readArrayLen();
                    for ($s = 0; $s < $count; $s++) {
                        $spans[] = $this->decodeSpan();
                    }
                    break;
                case self::CHUNK_ORIGIN:
                    $origin = $this->readInterned();
                    break;
                case self::CHUNK_PRIORITY:
                    $priority = $this->readInt();
                    break;
                case self::CHUNK_SAMPLING_MECHANISM:
                    $samplingMechanism = $this->readUint();
                    break;
                case self::CHUNK_ATTRIBUTES:
                    // Chunk-level attributes have no per-span v0.4 home; drain them.
                    $this->readAttributesMap();
                    break;
                case self::CHUNK_DROPPED_TRACE:
                    $this->readBool();
                    break;
                default:
                    $this->skipValue();
                    break;
            }
        }

        // Reconstruct the 128-bit trace id. The low 64 bits go on every span; the high 64 bits, when
        // non-zero, become meta['_dd.p.tid'] (hex) on the local-root span, mirroring the v0.4 wire.
        $traceIdLow = "0";
        $traceIdTidHex = null;
        if ($traceIdBytes !== null && strlen($traceIdBytes) === 16) {
            $high = substr($traceIdBytes, 0, 8);
            $low = substr($traceIdBytes, 8, 8);
            $traceIdLow = $this->bytesToDecimal($low);
            if ($high !== "\0\0\0\0\0\0\0\0") {
                $traceIdTidHex = bin2hex($high);
            }
        }

        foreach ($spans as &$span) {
            $span['trace_id'] = $traceIdLow;
        }
        unset($span);

        // Place the chunk-level, root-scoped propagation fields on the local-root span (the span with
        // no parent, else the one flagged _dd.top_level, else the first span) — the v0.4 layout.
        if (!empty($spans)) {
            $rootIdx = $this->findRootSpanIndex($spans);
            if ($traceIdTidHex !== null) {
                $spans[$rootIdx]['meta']['_dd.p.tid'] = $traceIdTidHex;
            }
            if ($origin !== null) {
                $spans[$rootIdx]['meta']['_dd.origin'] = $origin;
            }
            if ($samplingMechanism !== null) {
                // v0.4 stores the decision maker as "-<mechanism>".
                $spans[$rootIdx]['meta']['_dd.p.dm'] = "-" . $samplingMechanism;
            }
            if ($priority !== null) {
                $spans[$rootIdx]['metrics']['_sampling_priority_v1'] = $priority;
            }
        }

        // Drop empty meta/metrics/meta_struct so json_encode matches the v0.4 shape (absent, not {}).
        foreach ($spans as &$span) {
            foreach (['meta', 'metrics', 'meta_struct'] as $k) {
                if (isset($span[$k]) && count($span[$k]) === 0) {
                    unset($span[$k]);
                }
            }
        }
        unset($span);

        return ['spans' => $spans];
    }

    private function findRootSpanIndex(array $spans)
    {
        foreach ($spans as $idx => $span) {
            if (!isset($span['parent_id']) || $span['parent_id'] === "0") {
                return $idx;
            }
        }
        foreach ($spans as $idx => $span) {
            if (isset($span['metrics']['_dd.top_level']) && (float)$span['metrics']['_dd.top_level'] == 1.0) {
                return $idx;
            }
        }
        return 0;
    }

    private function decodeSpan()
    {
        $span = [
            'trace_id' => "0",
            'span_id' => "0",
            'parent_id' => "0",
            'name' => "",
            'resource' => "",
            'service' => "",
            'error' => 0,
            'meta' => [],
            'metrics' => [],
        ];
        $metaStruct = [];
        $kind = null;
        $links = null;
        $events = null;

        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            switch ($key) {
                case self::SPAN_SERVICE:  $span['service'] = $this->readInterned(); break;
                case self::SPAN_NAME:     $span['name'] = $this->readInterned(); break;
                case self::SPAN_RESOURCE: $span['resource'] = $this->readInterned(); break;
                case self::SPAN_SPAN_ID:  $span['span_id'] = (string)$this->readUint(); break;
                case self::SPAN_PARENT_ID:$span['parent_id'] = (string)$this->readUint(); break;
                case self::SPAN_START:    $span['start'] = $this->readUint(); break;
                case self::SPAN_DURATION: $span['duration'] = $this->readUint(); break;
                case self::SPAN_ERROR:    $span['error'] = $this->readBool() ? 1 : 0; break;
                case self::SPAN_TYPE:     $span['type'] = $this->readInterned(); break;
                case self::SPAN_ATTRIBUTES:
                    $this->readSpanAttributes($span['meta'], $span['metrics'], $metaStruct);
                    break;
                case self::SPAN_LINKS:    $links = $this->readSpanLinks(); break;
                case self::SPAN_EVENTS:   $events = $this->readSpanEvents(); break;
                case self::SPAN_ENV:      $span['meta']['env'] = $this->readInterned(); break;
                case self::SPAN_VERSION:  $span['meta']['version'] = $this->readInterned(); break;
                case self::SPAN_COMPONENT:$span['meta']['component'] = $this->readInterned(); break;
                case self::SPAN_KIND:     $kind = $this->readUint(); break;
                default:                  $this->skipValue(); break;
            }
        }

        // span.kind (uint) -> meta['span.kind']; Internal(1)/unspecified(0) leave no meta entry, so an
        // absent-in-v0.4 span.kind stays absent (the OTEL default is emitted unconditionally on v1).
        if ($kind !== null) {
            $kindStr = $this->spanKindToStr($kind);
            if ($kindStr !== null) {
                $span['meta']['span.kind'] = $kindStr;
            }
        }

        if ($links !== null && !empty($links)) {
            $span['meta']['_dd.span_links'] = json_encode($links, JSON_UNESCAPED_SLASHES);
        }
        if ($events !== null && !empty($events)) {
            $span['meta']['events'] = json_encode($events, JSON_UNESCAPED_SLASHES);
        }

        if (!empty($metaStruct)) {
            $span['meta_struct'] = $metaStruct;
        }

        return $span;
    }

    private function spanKindToStr($kind)
    {
        switch ($kind) {
            case 2: return "server";
            case 3: return "client";
            case 4: return "producer";
            case 5: return "consumer";
            // 1 (Internal) and 0 (Unspecified): no v0.4 meta entry.
            default: return null;
        }
    }

    /** Splits the unified v1 attributes map into v0.4 meta (String), metrics (Int/Double) and
     *  meta_struct (Bytes). Bool/Array/KeyValueList (not emitted by the PHP tracer) fall back to a
     *  JSON string in meta so nothing is silently dropped. */
    private function readSpanAttributes(&$meta, &$metrics, &$metaStruct)
    {
        $n = $this->readArrayLen();
        if ($n % 3 !== 0) {
            throw new \RuntimeException("v1 attributes flat array length $n is not a multiple of 3");
        }
        $entries = intdiv($n, 3);
        for ($i = 0; $i < $entries; $i++) {
            $key = $this->readInterned();
            list($type, $value) = $this->readTypedValue();
            switch ($type) {
                case self::ANY_STRING:
                    $meta[$key] = $value;
                    break;
                case self::ANY_INT64:
                case self::ANY_DOUBLE:
                    $metrics[$key] = $value;
                    break;
                case self::ANY_BYTES:
                    $metaStruct[$key] = $value;
                    break;
                case self::ANY_BOOL:
                case self::ANY_ARRAY:
                case self::ANY_KEY_VALUE_LIST:
                default:
                    $meta[$key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
                    break;
            }
        }
    }

    /** Reads a v1 attributes map into a plain associative array (used for link/event attributes). */
    private function readAttributesMap()
    {
        $out = [];
        $n = $this->readArrayLen();
        if ($n % 3 !== 0) {
            throw new \RuntimeException("v1 attributes flat array length $n is not a multiple of 3");
        }
        $entries = intdiv($n, 3);
        for ($i = 0; $i < $entries; $i++) {
            $key = $this->readInterned();
            list(, $value) = $this->readTypedValue();
            $out[$key] = $value;
        }
        return $out;
    }

    /** Reads `[type_uint8, value]`, returning [$type, $phpValue]. */
    private function readTypedValue()
    {
        $type = $this->readUint();
        switch ($type) {
            case self::ANY_STRING: return [$type, $this->readInterned()];
            case self::ANY_BOOL:   return [$type, $this->readBool()];
            case self::ANY_DOUBLE: return [$type, $this->readDouble()];
            case self::ANY_INT64:  return [$type, $this->readInt()];
            case self::ANY_BYTES:  return [$type, $this->readBin()];
            case self::ANY_ARRAY:
                $n = $this->readArrayLen();
                if ($n % 2 !== 0) {
                    throw new \RuntimeException("v1 typed array length $n is not a multiple of 2");
                }
                $items = [];
                for ($i = 0, $c = intdiv($n, 2); $i < $c; $i++) {
                    list(, $v) = $this->readTypedValue();
                    $items[] = $v;
                }
                return [$type, $items];
            case self::ANY_KEY_VALUE_LIST:
                return [$type, $this->readAttributesMap()];
            default:
                throw new \RuntimeException("Unknown v1 AnyValue type discriminant: $type");
        }
    }

    /** Native v1 span links -> the v0.4 `_dd.span_links` JSON element shape (trace_id 32-hex,
     *  span_id 16-hex, trace_state, attributes). */
    private function readSpanLinks()
    {
        $out = [];
        $count = $this->readArrayLen();
        for ($i = 0; $i < $count; $i++) {
            $link = [];
            $traceIdHex = str_repeat("0", 32);
            $spanIdHex = str_repeat("0", 16);
            $traceState = "";
            $attributes = [];
            $mapLen = $this->readMapLen();
            for ($j = 0; $j < $mapLen; $j++) {
                $key = $this->readUint();
                switch ($key) {
                    case self::LINK_TRACE_ID:
                        $b = $this->readBin();
                        $traceIdHex = str_pad(bin2hex($b), 32, "0", STR_PAD_LEFT);
                        break;
                    case self::LINK_SPAN_ID:
                        $spanIdHex = str_pad(dechex_gmp($this->readUint()), 16, "0", STR_PAD_LEFT);
                        break;
                    case self::LINK_ATTRIBUTES:
                        $attributes = $this->readAttributesMap();
                        break;
                    case self::LINK_TRACE_STATE:
                        $traceState = $this->readInterned();
                        break;
                    case self::LINK_FLAGS:
                        $this->readUint();
                        break;
                    default:
                        $this->skipValue();
                        break;
                }
            }
            $link['trace_id'] = $traceIdHex;
            $link['span_id'] = $spanIdHex;
            if ($traceState !== "") {
                $link['trace_state'] = $traceState;
            }
            if (!empty($attributes)) {
                $link['attributes'] = $attributes;
            }
            $out[] = $link;
        }
        return $out;
    }

    /** Native v1 span events -> the v0.4 `events` JSON element shape (name, time_unix_nano,
     *  attributes). */
    private function readSpanEvents()
    {
        $out = [];
        $count = $this->readArrayLen();
        for ($i = 0; $i < $count; $i++) {
            $event = [];
            $mapLen = $this->readMapLen();
            for ($j = 0; $j < $mapLen; $j++) {
                $key = $this->readUint();
                switch ($key) {
                    case self::EVENT_TIME:       $event['time_unix_nano'] = $this->readUint(); break;
                    case self::EVENT_NAME:       $event['name'] = $this->readInterned(); break;
                    case self::EVENT_ATTRIBUTES: $event['attributes'] = $this->readAttributesMap(); break;
                    default:                     $this->skipValue(); break;
                }
            }
            $out[] = $event;
        }
        return $out;
    }

    // --- streaming msgpack primitives -------------------------------------------------------------

    private function peek()
    {
        if ($this->pos >= $this->len) {
            throw new \RuntimeException("v1 decode: unexpected end of buffer");
        }
        return ord($this->buf[$this->pos]);
    }

    private function take($n)
    {
        if ($this->pos + $n > $this->len) {
            throw new \RuntimeException("v1 decode: buffer truncated");
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    private function readMapLen()
    {
        $m = ord($this->take(1));
        if ($m >= 0x80 && $m <= 0x8f) return $m & 0x0f;
        if ($m === 0xde) return $this->beUint($this->take(2));
        if ($m === 0xdf) return $this->beUint($this->take(4));
        throw new \RuntimeException(sprintf("v1 decode: expected map marker, got 0x%02x", $m));
    }

    private function readArrayLen()
    {
        $m = ord($this->take(1));
        if ($m >= 0x90 && $m <= 0x9f) return $m & 0x0f;
        if ($m === 0xdc) return $this->beUint($this->take(2));
        if ($m === 0xdd) return $this->beUint($this->take(4));
        throw new \RuntimeException(sprintf("v1 decode: expected array marker, got 0x%02x", $m));
    }

    /** Reads an unsigned integer; returns an int when it fits in PHP_INT, else a decimal string. */
    private function readUint()
    {
        $m = ord($this->take(1));
        if ($m <= 0x7f) return $m;              // positive fixint
        if ($m === 0xcc) return $this->beUint($this->take(1));
        if ($m === 0xcd) return $this->beUint($this->take(2));
        if ($m === 0xce) return $this->beUint($this->take(4));
        if ($m === 0xcf) return $this->beUint($this->take(8));
        throw new \RuntimeException(sprintf("v1 decode: expected uint marker, got 0x%02x", $m));
    }

    /** Reads a signed integer (any int marker). */
    private function readInt()
    {
        $m = $this->peek();
        if ($m <= 0x7f || ($m >= 0xcc && $m <= 0xcf)) {
            return $this->readUint();
        }
        $this->take(1);
        if ($m >= 0xe0) return $m - 0x100;      // negative fixint
        switch ($m) {
            case 0xd0: $v = ord($this->take(1)); return $v < 0x80 ? $v : $v - 0x100;
            case 0xd1: $v = $this->beUint($this->take(2)); return $v < 0x8000 ? $v : $v - 0x10000;
            case 0xd2: $v = $this->beUint($this->take(4)); return $v < 0x80000000 ? $v : $v - 0x100000000;
            case 0xd3:
                $bytes = $this->take(8);
                $u = gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
                if (gmp_testbit($u, 63)) {
                    $u = gmp_sub($u, gmp_pow(2, 64));
                }
                return $this->gmpToScalar($u);
        }
        throw new \RuntimeException(sprintf("v1 decode: expected int marker, got 0x%02x", $m));
    }

    private function readDouble()
    {
        $m = ord($this->take(1));
        if ($m === 0xcb) {
            $v = unpack("E", $this->take(8));
            return $v[1];
        }
        if ($m === 0xca) {
            $v = unpack("G", $this->take(4));
            return $v[1];
        }
        throw new \RuntimeException(sprintf("v1 decode: expected float marker, got 0x%02x", $m));
    }

    private function readBool()
    {
        $m = ord($this->take(1));
        if ($m === 0xc3) return true;
        if ($m === 0xc2) return false;
        throw new \RuntimeException(sprintf("v1 decode: expected bool marker, got 0x%02x", $m));
    }

    private function readStr()
    {
        $m = ord($this->take(1));
        if ($m >= 0xa0 && $m <= 0xbf) return $this->take($m & 0x1f);
        if ($m === 0xd9) return $this->take(ord($this->take(1)));
        if ($m === 0xda) return $this->take($this->beUint($this->take(2)));
        if ($m === 0xdb) return $this->take($this->beUint($this->take(4)));
        throw new \RuntimeException(sprintf("v1 decode: expected str marker, got 0x%02x", $m));
    }

    private function readBin()
    {
        $m = ord($this->take(1));
        if ($m === 0xc4) return $this->take(ord($this->take(1)));
        if ($m === 0xc5) return $this->take($this->beUint($this->take(2)));
        if ($m === 0xc6) return $this->take($this->beUint($this->take(4)));
        throw new \RuntimeException(sprintf("v1 decode: expected bin marker, got 0x%02x", $m));
    }

    /** Reads a string-or-reference: inline `str` (recorded into the table) or a `uint` table index. */
    private function readInterned()
    {
        $m = $this->peek();
        if (($m >= 0xa0 && $m <= 0xbf) || $m === 0xd9 || $m === 0xda || $m === 0xdb) {
            $s = $this->readStr();
            $this->table[] = $s;
            return $s;
        }
        if ($m <= 0x7f || ($m >= 0xcc && $m <= 0xcf)) {
            $id = $this->readUint();
            if (!isset($this->table[$id])) {
                throw new \RuntimeException("v1 decode: string table reference out of range: $id");
            }
            return $this->table[$id];
        }
        throw new \RuntimeException(sprintf("v1 decode: unexpected marker 0x%02x for interned string", $m));
    }

    /** Skips one arbitrary msgpack value, recording any inline string it contains into the table
     *  (so back-references in later known fields stay in sync). */
    private function skipValue()
    {
        $m = $this->peek();
        // str: record into the table
        if (($m >= 0xa0 && $m <= 0xbf) || $m === 0xd9 || $m === 0xda || $m === 0xdb) {
            $s = $this->readStr();
            $this->table[] = $s;
            return;
        }
        if ($m >= 0x80 && $m <= 0x8f || $m === 0xde || $m === 0xdf) {
            $n = $this->readMapLen();
            for ($i = 0; $i < $n; $i++) { $this->skipValue(); $this->skipValue(); }
            return;
        }
        if ($m >= 0x90 && $m <= 0x9f || $m === 0xdc || $m === 0xdd) {
            $n = $this->readArrayLen();
            for ($i = 0; $i < $n; $i++) { $this->skipValue(); }
            return;
        }
        if ($m === 0xc4 || $m === 0xc5 || $m === 0xc6) { $this->readBin(); return; }
        if ($m === 0xc0) { $this->take(1); return; }                 // nil
        if ($m === 0xc2 || $m === 0xc3) { $this->take(1); return; }  // bool
        if ($m === 0xca) { $this->take(5); return; }                 // float32
        if ($m === 0xcb) { $this->take(9); return; }                 // float64
        if ($m <= 0x7f || $m >= 0xe0) { $this->take(1); return; }    // fixint
        if ($m >= 0xcc && $m <= 0xcf) { $this->readUint(); return; } // uint
        if ($m >= 0xd0 && $m <= 0xd3) { $this->readInt(); return; }  // int
        throw new \RuntimeException(sprintf("v1 decode: cannot skip marker 0x%02x", $m));
    }

    /** Big-endian unsigned from up to 8 bytes; returns int when it fits, else a decimal string. */
    private function beUint($bytes)
    {
        $n = strlen($bytes);
        if ($n <= 4) {
            $v = 0;
            for ($i = 0; $i < $n; $i++) { $v = ($v << 8) | ord($bytes[$i]); }
            return $v;
        }
        return $this->gmpToScalar(gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
    }

    private function bytesToDecimal($bytes)
    {
        return gmp_strval(gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
    }

    private function gmpToScalar($g)
    {
        // Keep small values as native ints (so json_encode emits `1`, not `"1"`); overflow -> string.
        if (gmp_cmp($g, PHP_INT_MAX) <= 0 && gmp_cmp($g, PHP_INT_MIN) >= 0) {
            return gmp_intval($g);
        }
        return gmp_strval($g);
    }
}

/** dechex() that also handles values returned as decimal strings (uint64 > PHP_INT_MAX). */
function dechex_gmp($v)
{
    if (is_int($v)) {
        return dechex($v);
    }
    return gmp_strval(gmp_init((string)$v, 10), 16);
}
