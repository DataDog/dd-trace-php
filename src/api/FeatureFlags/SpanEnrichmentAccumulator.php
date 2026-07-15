<?php

namespace DDTrace\FeatureFlags;

/**
 * Per-root-span accumulator + codec for APM feature-flag span enrichment.
 *
 * This is a verbatim port of the frozen Node.js reference
 * (dd-trace-js#8343 — `encoding.js` / `span-enrichment.js`). The encoding,
 * limits, dedupe rules, runtime-default detection, and tag shapes are FROZEN
 * against that contract: the backend/Trino decode side and the parametric
 * system-tests assert exact parity, so any divergence (signed varint, wrong
 * sort, `[object Object]` defaults, bare-vs-JSON tag shape) breaks the chain.
 *
 * Lifecycle: one instance per root span, request-scoped. The DataDogProvider
 * constructs it lazily only when the span-enrichment gate is on (DG-005), and
 * the native close-span path flushes + clears it on root-span finish.
 */
final class SpanEnrichmentAccumulator
{
    const TAG_FLAGS = 'ffe_flags_enc';
    const TAG_SUBJECTS = 'ffe_subjects_enc';
    const TAG_RUNTIME_DEFAULTS = 'ffe_runtime_defaults';

    const MAX_SERIAL_IDS = 200;
    const MAX_SUBJECTS = 10;
    const MAX_EXPERIMENTS_PER_SUBJECT = 20;
    const MAX_DEFAULTS = 5;
    const MAX_DEFAULT_VALUE_LENGTH = 64;

    /** @var array<int, true> Set of unique serial ids (dedupe-before-encode). */
    private $serialIds = array();

    /** @var array<string, array<int, true>> sha256hex => set of serial ids. */
    private $subjects = array();

    /** @var array<string, string> flagKey => stringified default value. */
    private $defaults = array();

    /**
     * Record a serial id seen during evaluation. Deduped via a set; dropped
     * (with no error) once the frozen cap is reached.
     */
    public function addSerialId($id)
    {
        $id = (int) $id;
        if (isset($this->serialIds[$id])) {
            return;
        }
        if (count($this->serialIds) >= self::MAX_SERIAL_IDS) {
            return;
        }
        $this->serialIds[$id] = true;
    }

    /**
     * Associate a serial id with a (hashed) subject. The targeting key is
     * SHA256-hashed before storage (privacy contract DG-003) and is only ever
     * called by the provider when `do_log` authorizes it.
     */
    public function addSubject($targetingKey, $id)
    {
        $id = (int) $id;
        $hashed = $this->hashTargetingKey((string) $targetingKey);

        if (isset($this->subjects[$hashed])) {
            if (isset($this->subjects[$hashed][$id])) {
                return;
            }
            if (count($this->subjects[$hashed]) >= self::MAX_EXPERIMENTS_PER_SUBJECT) {
                return;
            }
            $this->subjects[$hashed][$id] = true;
            return;
        }

        if (count($this->subjects) >= self::MAX_SUBJECTS) {
            return;
        }
        $this->subjects[$hashed] = array($id => true);
    }

    /**
     * Record a runtime-default value for a flag (first-wins). Object/array
     * values are JSON-encoded (never the implicit "Array"/"[object Object]"
     * cast); the stringified value is truncated to the frozen length budget in
     * a UTF-8-safe manner.
     *
     * @param mixed $value
     */
    public function addDefault($flagKey, $value)
    {
        $flagKey = (string) $flagKey;
        if (array_key_exists($flagKey, $this->defaults)) {
            return;
        }
        if (count($this->defaults) >= self::MAX_DEFAULTS) {
            return;
        }

        $this->defaults[$flagKey] = $this->stringifyDefault($value);
    }

    /**
     * Whether the accumulator carries anything worth writing. Mirrors the Node
     * reference: subjects are intentionally NOT checked, because addSubject is
     * never reached without a preceding addSerialId.
     */
    public function hasData()
    {
        return count($this->serialIds) > 0 || count($this->defaults) > 0;
    }

    /**
     * Encode the accumulated state into the frozen `ffe_*` span tag set.
     *
     * Output-shape contract (Pattern F):
     *  - ffe_flags_enc        => BARE base64 string
     *  - ffe_subjects_enc     => JSON-stringified object {sha256hex: base64}
     *  - ffe_runtime_defaults => JSON-stringified object {flagKey: valueStr}
     *
     * Empty components are omitted entirely.
     *
     * @return array<string, string>
     */
    public function toSpanTags()
    {
        $tags = array();

        if (count($this->serialIds) > 0) {
            $tags[self::TAG_FLAGS] = $this->encodeDeltaVarint(array_keys($this->serialIds));
        }

        if (count($this->subjects) > 0) {
            $encodedSubjects = array();
            foreach ($this->subjects as $hashed => $ids) {
                $encodedSubjects[$hashed] = $this->encodeDeltaVarint(array_keys($ids));
            }
            // JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES match Node JSON.stringify
            // byte-for-byte: raw UTF-8 (no \uXXXX) and bare '/' (base64 ids contain '/').
            $tags[self::TAG_SUBJECTS] = json_encode($encodedSubjects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (count($this->defaults) > 0) {
            $tags[self::TAG_RUNTIME_DEFAULTS] = json_encode($this->defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $tags;
    }

    /**
     * Reset all accumulated state. Called after the tags are flushed onto the
     * root span so a reused accumulator never leaks across spans/requests.
     */
    public function clear()
    {
        $this->serialIds = array();
        $this->subjects = array();
        $this->defaults = array();
    }

    /**
     * ULEB128 delta-varint + base64 encoder (frozen).
     *
     * Rules (must match Node + the L2 decoder exactly): dedupe via set, sort
     * ascending, delta-from-previous (first delta = first value), UNSIGNED
     * LEB128 (7 bits/byte, MSB = continuation). Empty input encodes to "" so
     * the caller omits the tag.
     *
     * Golden vector: [100,108,128,130] => "ZAgUAg==".
     *
     * @param array<int, int> $ids
     * @return string
     */
    private function encodeDeltaVarint(array $ids)
    {
        if (count($ids) === 0) {
            return '';
        }

        $unique = array();
        foreach ($ids as $id) {
            $unique[(int) $id] = true;
        }
        $sorted = array_keys($unique);
        sort($sorted, SORT_NUMERIC);

        $buffer = '';
        $prev = 0;
        foreach ($sorted as $id) {
            $delta = $id - $prev;
            $prev = $id;

            // Unsigned LEB128 of the non-negative delta.
            while ($delta > 0x7F) {
                $buffer .= chr(($delta & 0x7F) | 0x80);
                $delta >>= 7;
            }
            $buffer .= chr($delta & 0x7F);
        }

        return base64_encode($buffer);
    }

    /**
     * Decode a delta-varint base64 string back into the serial-id set. Provided
     * for round-trip self-tests (the L2 `utils.py` decoder is the authority);
     * not used on the write path.
     *
     * @return array<int, int>
     */
    public function decodeDeltaVarint($encoded)
    {
        $ids = array();
        if (!is_string($encoded) || $encoded === '') {
            return $ids;
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            return $ids;
        }

        $prev = 0;
        $shift = 0;
        $delta = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            $delta |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                $prev += $delta;
                $ids[] = $prev;
                $delta = 0;
                $shift = 0;
            } else {
                $shift += 7;
            }
        }

        return $ids;
    }

    /**
     * Lowercase hex SHA256 of the targeting key (frozen; stdlib ext-hash).
     */
    private function hashTargetingKey($key)
    {
        return hash('sha256', $key);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function stringifyDefault($value)
    {
        if (is_array($value) || is_object($value)) {
            // Match Node JSON.stringify: raw UTF-8 (no \uXXXX) and bare '/'. Default
            // json_encode escapes both, which both breaks byte-parity AND inflates the
            // length so the 64-char truncation can cut mid-escape-sequence.
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $valueStr = $encoded === false ? '' : $encoded;
        } elseif (is_bool($value)) {
            // Match the Node String(boolean) form: "true"/"false".
            $valueStr = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $valueStr = 'null';
        } else {
            $valueStr = (string) $value;
        }

        return $this->truncateUtf8($valueStr, self::MAX_DEFAULT_VALUE_LENGTH);
    }

    /**
     * Truncate to at most $maxLength characters without splitting a multi-byte
     * UTF-8 sequence. Falls back to a byte-safe trim if the multibyte helpers
     * are unavailable.
     */
    private function truncateUtf8($value, $maxLength)
    {
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($value, 'UTF-8') <= $maxLength) {
                return $value;
            }
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return $this->truncateUtf8ByteFallback($value, $maxLength);
    }

    /**
     * mbstring-free fallback for truncateUtf8(). Walks codepoint-by-codepoint
     * (via UTF-8 leading-byte length) so the $maxLength cutoff counts
     * characters, not bytes -- a byte cutoff would truncate multi-byte text
     * (e.g. CJK, emoji) far below $maxLength chars. Kept as its own method so
     * it is directly testable regardless of whether ext-mbstring is loaded in
     * the environment running the tests.
     */
    private function truncateUtf8ByteFallback($value, $maxLength)
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        $len = strlen($value);
        $offset = 0;
        $count = 0;
        while ($offset < $len && $count < $maxLength) {
            $byte = ord($value[$offset]);
            if ($byte < 0x80) {
                $seqLen = 1;
            } elseif (($byte & 0xE0) === 0xC0) {
                $seqLen = 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $seqLen = 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                $seqLen = 4;
            } else {
                $seqLen = 1;
            }
            if ($offset + $seqLen > $len) {
                break;
            }
            $offset += $seqLen;
            $count++;
        }

        return substr($value, 0, $offset);
    }
}
