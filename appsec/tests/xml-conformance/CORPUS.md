# XML PHPT corpus export

`cases.jsonl` contains every `convert_xml` invocation from the 41 original PHPT
snapshots in `sources/`: 282 repository cases and 61 proposed cases. Duplicate
inputs remain separate so their original expectations and authority survive.
`tests.json` preserves full expected stdout, EXPECT/EXPECTF kind, INI settings,
source hashes, and links to every original PHPT snapshot.

Run `uv run export.py` to regenerate the corpus using only the snapshots and
local PHP. No ddtrace extension or XML parser is loaded or called. Run with
`--repo-source PATH --proposed-source PATH --output PATH` to capture other PHPT
directories; `--php PATH` selects PHP. Source paths in exported metadata are
relative to the exported snapshots and require no original checkout or
/tmp path.

The exporter evaluates the original PHP FILE sections against a namespaced
`datadog\appsec\convert_xml` stub under `php -n`. It records exact argument
bytes and returns the corresponding existing expectation. This preserves loops,
heredocs, generated nesting, variable content types, and external-entity count
assertions. It verifies the original PHP output against the expectation after
substituting those results. The ISO-8859-1 case's extension-generated notice is
not replayed, but its complete EXPECTF remains in tests.json and the snapshot.

Case fields:

- `id`: stable test group, filename, 1-based invocation index, and case label.
- `origin_test`, `origin_case`, `origin_call_index`, `origin_php_line`:
  provenance;
  line numbers refer to extracted FILE sections, not complete PHPTs.
- `authority`: `approved_repository_expectation` or
  `unapproved_proposed_expectation`; this describes source membership, not a new
  judgment that the expected behavior is correct.
- `xml`: `{ "encoding": "utf-8" | "base64", "data": STRING }`. Base64 is used
  for invalid UTF-8. UTF-8 data may contain JSON-escaped control bytes.
- `xml_byte_length`, `xml_sha256`: lossless byte verification.
- `content_type`: exact PHP argument, including quoting and escapes.
- `max_depth`: 30, fixed by `MAX_XML_DEPTH` in entity_body.c; these tests do not
  configure depth via INI. All original INI settings remain in tests.json.
- `expected`: existing result `value`, exact `source_text`, and derivation.

All 343 result expectations are derivable as JSON or PHP's printed NULL and all
41 result-to-call mappings pass the output replay check. Capture stdout/stderr,
raw base64 captures, and extracted FILE sections remain under `runtime/` for
inspection; these intermediates need not be persisted with a downstream suite.
The export does not determine parser acceptance/completion status, which is not
exposed by convert_xml, and does not run or validate the actual implementation.

To add a new PHPT with non-JSON assertions or result-dependent control flow,
review the extraction and replay behavior. The exporter deliberately fails when
result counts or replay output do not match; it must not silently omit a case.
