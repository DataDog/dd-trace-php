# anyxml SAX body-parser prototype

This executable adapts SAX callbacks from **anyxml 0.15.1 with local patches**
to the production `AppsecBodyTape` representation. It creates no XML DOM and
remains outside the extension's Cargo workspace and dependency graph.
Production integration is still pending.

`build.rs` compiles the tape definitions and writer directly from
`components-rs/appsec_body/mod.rs`, plus the current `xml.rs` and `xml_dtd.rs`.
It asserts the extraction boundaries and rebuilds when those files change.
There is no independently maintained copy of the writer. The harness decodes
the resulting tape into JSON for comparison with the captured PHPT outputs.

## Run

From the repository root:

```sh
uv run appsec/tests/xml-conformance/run.py --parser anyxml \
  --output appsec/tests/xml-conformance/results/anyxml
uv run appsec/tests/xml-conformance/run.py --parser current \
  --output appsec/tests/xml-conformance/results/current
cargo test --locked \
  --manifest-path appsec/tests/xml-conformance/anyxml/Cargo.toml
```

The runner first runs `uv run appsec/tests/xml-conformance/anyxml/prepare.py`.
Run that command yourself before invoking Cargo on a fresh checkout. It
downloads the exact published crate, verifies its original Cargo checksum,
and applies [internal-parameter-entities.patch](internal-parameter-entities.patch),
[resource-limits.patch](resource-limits.patch),
[standalone-whitespace.patch](standalone-whitespace.patch),
[skipped-dtd-entities.patch](skipped-dtd-entities.patch), and
[character-reference-events.patch](character-reference-events.patch) under the
ignored `vendor/` directory. It preserves the upstream license and does not modify Cargo's
registry cache. Subsequent preparation reuses the source unless a patch
changes; the archive is cached for rebuilding it.

The runner uses one subprocess per configuration, four workers, and a
ten-second timeout. Both selectors support only SAX mode. `--case REGEX`
selects retained cases; `--binary PATH` reuses a compiled harness. All original
PHPT snapshots and expectations remain unchanged.

The executable accepts JSON lines with `name`, exactly one of `xml` or
`xml_base64`, and optional `max_depth` (default 30). `--current` selects the
production parser compiled into the same executable. Input is capped at
1 MiB after decoding.

## Adapter behavior

- Directly emits maps, lists, attributes and coalesced text to `TapeWriter`.
- Implements the existing recording-depth policy, including attributes on
  elements at the limit. anyxml continues checking suppressed XML.
- Preserves qualified names and omits namespace declaration attributes.
  Namespace validation is disabled to match the existing body API.
- Processes internal parameter entities with validation disabled. The local
  patch removes their validation gate while preserving external-entity and
  undeclared-parameter-entity option gates.
- Disables external general and parameter entity loading and catalog
  resolution. Both resolver callbacks also deny access. Skipped external
  general entities remain literal references in the body output.
- Retains emitted callbacks before fatal errors and closes all open tape
  containers. It does not invent events missing from the SAX stream.
- Accepts UTF-8 input only, retains the valid prefix on malformed UTF-8, and
  removes one document BOM before parsing with an explicit UTF-8 encoding.
- Preserves CDATA, entity, and numeric-reference whitespace using lexical
  callbacks. Literal whitespace-only runs retain the existing omission policy.

`accepted` measures XML acceptance for the original UTF-8 input, independently
of recording policy. `body_accepted` also requires the body policy to pass and
recording to complete. For example, a legal ISO-8859-1 declaration can have
`accepted: true`, `body_accepted: false`, and a `body_policy_error`. Invalid
UTF-8 makes `accepted` false even if its valid prefix parses successfully.
`status` is the actual tape status; an empty tape renders as JSON null.

At most 64 diagnostics and 16 MiB of tape data are retained. These caps apply
to the adapter. The resource patch separately limits work inside anyxml, as
described below. `denied_resources` counts resolver callbacks, including
requests for a synthetic external subset; it does not count file or network
operations. The resolver performs none.

## Recorded results

The patched prototype was run on 2026-10-27 against the retained corpus. The
current-parser baseline was recorded on 2026-10-23 at revision `b66780c36`;
no production parser changes were made.
Machine-readable counts are in [comparison.json](comparison.json).

| Measurement | Current parser | anyxml prototype |
|---|---:|---:|
| Configurations exercised | 468 | 468 |
| Approved native-body outputs matched | 255/255 | 233/255 |
| Proposed native-body outputs matched | 30/52 | 39/52 |
| Outstanding XML acceptance expectations matched | 56/125 | 123/125 |
| Outstanding proposed outputs matched, where supplied | 9/23 | 9/23 |
| Execution failures or timeouts | 0 | 0 |

The 125 acceptance checks cover 114 distinct XML inputs, with 11 repeated at
recording depth one. The current parser's completion status is deliberately
tolerant, so this column is not a general parser-quality score. The only two
acceptance differences are recording-depth variants of a default-processing
stress input that exceeds the stricter resource budget described below.

Compared with the validation-enabled prototype at `d359f7664`, the patch and
configuration change improve one approved output: an external DOCTYPE with
ordinary element content now parses successfully. The approved and proposed
output match counts are unchanged by the stricter resource budget. The
patched run makes zero resolver calls across all 468 configurations. The
earlier counts remain in `comparison.json` as `anyxml_validation`.

Compared with the initial resource limits at `e86c53f43`, the stricter budget
alone changes only `limits/default-budget-starves-later-entity`, exercised at
recording depths 30 and 1. It applies 201 attribute declarations across 400
elements and now stops with `ResourceLimit`. Libxml and Expat accept this
input. The other 466 configurations retained their acceptance, body status,
and output before the compatibility patches.

The standalone declaration patch now accepts legal whitespace after `=` and
handles values crossing reader-buffer boundaries. It restores one approved
output without changing any previously matching result.

The skipped-DTD patch records the presence of an external subset even when
loading is disabled. Nonstandalone documents can then retain unresolved
content references and omit unresolved references in attributes and defaults.
This matches libxml acceptance, including its tolerance for undeclared
references in defaults. The patch restores three approved outputs, one
proposed output, and the remaining non-resource acceptance expectation.

The character-reference patch adds a lexical notification for numeric
references in element content. The adapter marks the surrounding text run
for preservation while normal text callbacks still deliver the characters.
It restores the whitespace-only numeric-reference output without changing
chunking, coalescing, or literal-whitespace handling. The notification is not
emitted while parsing attributes or entity declarations.

All three compatibility patches preserve previously matching expectations.
Disposable native probes also cover progressive input split within references,
handler forwarding, and the default notification handler without duplicate
text delivery. The retained corpus and test expectations are unchanged.

Thirty-six HTTP charset-wrapper cases are retained and exercised but excluded
from native-output comparisons. Proposed outputs are not approved behavior;
some require a different application whitespace or encoding policy. Counts
must not be interpreted as a claim of complete XML conformance.

The Rust suite passes 50 tests: 40 existing XML/DTD tests run against the
current implementation, and 10 adapter tests against anyxml. This does not
claim that all existing unit tests pass against the candidate.

The 22 remaining approved-output differences are:

| Cases | Difference |
|---:|---|
| 1 | A nested DOCTYPE inside a parameter entity is accepted. |
| 3 | Undeclared entities in defaults are tolerated when a parameter entity is declared but not previously referenced. |
| 1 | A CR introduced by a character reference into an entity's CDATA becomes LF. |
| 6 | Declarations after an unread parameter entity still affect the document. |
| 3 | Standalone defaults within parameter entities are rejected; the retained behavior follows Expat. |
| 6 | Fatal text containing `]]>` loses its preceding text within the same SAX token. |
| 2 | Incomplete start tags have no SAX start-element callback to retain. |

Every difference is reproducible from the unchanged corpus. For example:

```sh
uv run appsec/tests/xml-conformance/run.py --parser anyxml \
  --case 'single-quotes-and-whitespace|inside-parameter-entity|cdata_whitespace'
```

Four differences were also reproduced through native anyxml callbacks, without
the tape adapter, and checked against Expat 2.6.3 and libxml 2.9.13:

| Situation | anyxml | Expat, processing PEs | libxml 2.9.13 |
|---|---|---|---|
| `standalone = 'yes'` | Accepts after patch | Accepts | Accepts |
| `<!DOCTYPE nested>` inside a processed PE | Accepts | Rejects | Rejects |
| `<![CDATA[&#xD;]]>` in an entity declaration | Expanded text contains LF | Contains CR | Contains LF |
| Declarations after an unread external PE | Still applied | Ignored | Still applied |

The first two were parser errors with agreement between both references;
the standalone declaration error is now fixed.
Expat skips the nested-DOCTYPE case if PE processing is disabled; that is a
different configuration. XML line-ending normalization applies to external
parsed entities, so the CR introduced into internal replacement text should
survive. Nonvalidating XML processing must ignore subsequent entity and
attribute-list declarations after an unread PE, unless standalone is yes.
Local libxml shares anyxml's behavior for those latter two cases.

With external PE loading disabled, skipped external PEs still do not suppress
subsequent declarations. This patch does not address that separate behavior.
Expat itself omits unresolved entity text; preserving its literal reference
is an application policy, not part of that native-parser comparison.

## Parser resource limits

The resource patch uses libxml-style amplification accounting, with both a
strict amplification bound and an absolute ceiling. These are fixed limits
in the patched parser, separate from the adapter's output and recording-depth
limits:

| Resource | Limit |
|---|---|
| Expansion work | Lesser of 8 MiB or 4 times document bytes consumed |
| Entity nesting | 64 source levels |
| Element nesting | 256 elements |
| Recursive DTD parsing | 256 levels |

The shared work counter charges internal general and parameter entity
replacement lengths and reference names before creating replacement sources.
Each charge adds 20 units, so empty replacements still consume the budget.
It covers content, attribute values, entity values, and DTD default values,
including progressive parsing. Internal replacement sources never increase
the input allowance. Document consumption counts decoded UTF-8 bytes actually
advanced over, excluding read-ahead, and survives buffer compaction.
There is no initial allowance: the amplification bound applies from the first
expansion, and 8 MiB is a hard ceiling rather than a minimum budget.

Applying an attribute list charges each declaration visit, the explicit
attribute names placed in the lookup set, and copied default names and values.
The set avoids quadratic searches through defaults already added to the
element. Checked nesting and saturating accounting stop parsing with
`ResourceLimit` before the operation that would exceed a limit. Overflow also
fails. Progressive parsing remains stopped until `reset()` starts a new
document; the adapter retains callbacks emitted before the failure.

DTD content models are still parsed, but validation automata are compiled
only when validation is enabled. This avoids unnecessary recursive and
potentially exponential compilation in the prototype's nonvalidating mode.
Validation and external resource loading remain disabled in the adapter;
the patch does not establish a work bound for those other configurations.

anyxml 0.15.1 exposes only feature flags in `ParserConfig`. SAX callbacks
return `()`, and parser cancellation state is private. Progressive parsing
does not solve this: one `parse_chunk` call can expand many entities before
returning. Attribute and DTD-default expansion deliberately omit entity
callbacks and happen before the resulting attribute/declaration callback.
Default application also happens before `start_element`.

These operations therefore need checks inside the parser. The patch uses
ordinary error returns to stop parsing; it does not rely on callback counters,
chunk sizes, or panic-based cancellation.

Disposable stress probes confirmed that excessive expansion, empty-entity
work, repeated default copying and iteration, and excessive nesting return
`ResourceLimit` without timeouts or abnormal exits. The retained 100,000-level
element input previously overflowed the process stack and now fails safely.
Controls at entity depth 64 and element depth 256 still succeed. The stricter
budget rejects the previous 7.68 MiB expansion control. A 1,360-byte document
that previously expanded to 100 KiB now stops at the work limit; a control
that expands the same entity twice still succeeds. Progressive failure
remains terminal, and reset permits a new document. The repository's existing
tests and corpus remain unchanged.

Primary source references for the pinned version:

- [SAX configuration](https://docs.rs/crate/anyxml/0.15.1/source/src/sax/parser.rs)
- [Callback API](https://docs.rs/crate/anyxml/0.15.1/source/src/sax/handler.rs)
- [Parameter entities](https://docs.rs/crate/anyxml/0.15.1/source/src/parse/mod.rs)
- [Attribute expansion](https://docs.rs/crate/anyxml/0.15.1/source/src/parse/literals.rs)
- [Default application](https://docs.rs/crate/anyxml/0.15.1/source/src/parse/element.rs)
