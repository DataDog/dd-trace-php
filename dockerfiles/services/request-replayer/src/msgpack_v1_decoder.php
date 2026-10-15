<?php

/**
 * Decoder for the v1 (`/v1.0/traces`) msgpack wire into a JSON view of the same payload.
 *
 * The view keeps the v1 structure and field names (libdatadog `libdd-trace-utils/src/span/v1/`):
 * payload fields and `attributes`, `chunks` with their own fields (trace_id, priority, origin,
 * sampling_mechanism, dropped_trace, attributes), and spans with their native fields. Only the wire
 * encoding is undone:
 *   - Map keys are integer proto field numbers, not strings.
 *   - Strings use a streaming intern table: an inline msgpack `str` (also appended to the table) or a
 *     `uint` index into it (index 0 == empty string).
 *   - `attributes` values are AnyValue-typed [type_uint8, value]:
 *     String(1)/Bool(2)/Double(3)/Int64(4)/Bytes(5)/Array(6)/KeyValueList(7); the view keeps the
 *     typed value (Bytes become `{"bytes": base64}`).
 * Ids: span_id/parent_id are decimal strings; 128-bit trace ids are 32 hex digits, link span ids 16.
 */
class V1TraceDecoder
{
    private $buf;
    private $pos = 0;
    private $len;
    /** @var string[] streaming intern table; index 0 is the empty string */
    private $table = [''];

    // Integer map keys, kept in sync with the libdatadog v1 encoder/decoder.
    const PAYLOAD_FIELDS = [2 => 'container_id', 3 => 'language_name', 4 => 'language_version',
        5 => 'tracer_version', 6 => 'runtime_id', 7 => 'env', 8 => 'hostname', 9 => 'app_version'];
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

    /** Decodes the whole payload into its JSON view. */
    public function decode()
    {
        $payload = [];
        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            if (isset(self::PAYLOAD_FIELDS[$key])) {
                $payload[self::PAYLOAD_FIELDS[$key]] = $this->readInterned();
                continue;
            }
            switch ($key) {
                case self::TRACE_CHUNKS:
                    $payload['chunks'] = [];
                    $count = $this->readArrayLen();
                    for ($c = 0; $c < $count; $c++) {
                        $payload['chunks'][] = $this->decodeChunk();
                    }
                    break;
                case self::TRACE_ATTRIBUTES:
                    $payload['attributes'] = $this->readAttributesMap();
                    break;
                default:
                    $this->skipValue();
                    break;
            }
        }
        return $payload;
    }

    private function decodeChunk()
    {
        $chunk = [];
        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            switch ($key) {
                case self::CHUNK_TRACE_ID:           $chunk['trace_id'] = bin2hex($this->readBin()); break;
                case self::CHUNK_PRIORITY:           $chunk['priority'] = $this->readInt(); break;
                case self::CHUNK_ORIGIN:             $chunk['origin'] = $this->readInterned(); break;
                case self::CHUNK_SAMPLING_MECHANISM: $chunk['sampling_mechanism'] = $this->readUint(); break;
                case self::CHUNK_DROPPED_TRACE:      $chunk['dropped_trace'] = $this->readBool(); break;
                case self::CHUNK_ATTRIBUTES:         $chunk['attributes'] = $this->readAttributesMap(); break;
                case self::CHUNK_SPANS:
                    $chunk['spans'] = [];
                    $count = $this->readArrayLen();
                    for ($s = 0; $s < $count; $s++) {
                        $chunk['spans'][] = $this->decodeSpan();
                    }
                    break;
                default:
                    $this->skipValue();
                    break;
            }
        }
        return $chunk;
    }

    private function decodeSpan()
    {
        $span = [];
        $mapLen = $this->readMapLen();
        for ($i = 0; $i < $mapLen; $i++) {
            $key = $this->readUint();
            switch ($key) {
                case self::SPAN_SERVICE:   $span['service'] = $this->readInterned(); break;
                case self::SPAN_NAME:      $span['name'] = $this->readInterned(); break;
                case self::SPAN_RESOURCE:  $span['resource'] = $this->readInterned(); break;
                case self::SPAN_TYPE:      $span['type'] = $this->readInterned(); break;
                case self::SPAN_SPAN_ID:   $span['span_id'] = (string)$this->readUint(); break;
                case self::SPAN_PARENT_ID: $span['parent_id'] = (string)$this->readUint(); break;
                case self::SPAN_START:     $span['start'] = $this->readInt(); break;
                case self::SPAN_DURATION:  $span['duration'] = $this->readInt(); break;
                case self::SPAN_ERROR:     $span['error'] = $this->readBool(); break;
                case self::SPAN_ENV:       $span['env'] = $this->readInterned(); break;
                case self::SPAN_VERSION:   $span['version'] = $this->readInterned(); break;
                case self::SPAN_COMPONENT: $span['component'] = $this->readInterned(); break;
                case self::SPAN_KIND:      $span['span_kind'] = $this->readUint(); break;
                case self::SPAN_ATTRIBUTES:$span['attributes'] = $this->readAttributesMap(); break;
                case self::SPAN_LINKS:     $span['span_links'] = $this->readSpanLinks(); break;
                case self::SPAN_EVENTS:    $span['span_events'] = $this->readSpanEvents(); break;
                default:                   $this->skipValue(); break;
            }
        }
        return $span;
    }

    /** Reads a v1 attributes map into an associative array of typed values. */
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
            $out[$key] = $this->readTypedValue();
        }
        return $out;
    }

    /** Reads `[type_uint8, value]` into its PHP value. */
    private function readTypedValue()
    {
        $type = $this->readUint();
        switch ($type) {
            case self::ANY_STRING: return $this->readInterned();
            case self::ANY_BOOL:   return $this->readBool();
            case self::ANY_DOUBLE: return $this->readDouble();
            case self::ANY_INT64:  return $this->readInt();
            case self::ANY_BYTES:  return ['bytes' => base64_encode($this->readBin())];
            case self::ANY_ARRAY:
                $n = $this->readArrayLen();
                if ($n % 2 !== 0) {
                    throw new \RuntimeException("v1 typed array length $n is not a multiple of 2");
                }
                $items = [];
                for ($i = 0, $c = intdiv($n, 2); $i < $c; $i++) {
                    $items[] = $this->readTypedValue();
                }
                return $items;
            case self::ANY_KEY_VALUE_LIST:
                return (object)$this->readAttributesMap();
            default:
                throw new \RuntimeException("Unknown v1 AnyValue type discriminant: $type");
        }
    }

    private function readSpanLinks()
    {
        $out = [];
        $count = $this->readArrayLen();
        for ($i = 0; $i < $count; $i++) {
            $link = [];
            $mapLen = $this->readMapLen();
            for ($j = 0; $j < $mapLen; $j++) {
                $key = $this->readUint();
                switch ($key) {
                    case self::LINK_TRACE_ID:    $link['trace_id'] = bin2hex($this->readBin()); break;
                    case self::LINK_SPAN_ID:
                        $link['span_id'] = str_pad(dechex_gmp($this->readUint()), 16, "0", STR_PAD_LEFT);
                        break;
                    case self::LINK_ATTRIBUTES:  $link['attributes'] = $this->readAttributesMap(); break;
                    case self::LINK_TRACE_STATE: $link['tracestate'] = $this->readInterned(); break;
                    case self::LINK_FLAGS:       $link['flags'] = $this->readUint(); break;
                    default:                     $this->skipValue(); break;
                }
            }
            $out[] = $link;
        }
        return $out;
    }

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
