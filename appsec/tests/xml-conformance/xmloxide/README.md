# Isolated xmloxide comparison harness

Pinned candidate: xmloxide **0.5.0**, selected by `cargo info xmloxide` on
2026-10-23. `Cargo.toml` uses `=0.5.0`, disables CLI/default features, and has
its own `[workspace]`. Keep `Cargo.lock` with this directory.

Build from this directory:

```sh
cargo build --release --locked
uv run smoke.py
```

Run JSONL on stdin, one response per input line:

```sh
target/release/xmloxide-body-harness < corpus.jsonl > sax-strict.jsonl
target/release/xmloxide-body-harness --recover \
    < corpus.jsonl > sax-recover.jsonl
target/release/xmloxide-body-harness --dom < corpus.jsonl > dom-strict.jsonl
target/release/xmloxide-body-harness --dom --recover \
    < corpus.jsonl > dom-recover.jsonl
```

Input: `{"name":"example","xml":"<r>tail</r>","max_depth":30}`.
`name` accepts any JSON value. `max_depth` defaults to 30 and controls only
adapter recording, not parser acceptance. Unknown fields are ignored.
`xml_base64` is also supported and takes precedence over `xml` if both occur.
It uses the library's `encoding::decode_to_utf8`, followed by the chosen
string parser. Encoding failures are returned with error stage
`library_encoding`. This permits the library's supported non-UTF-8 encodings;
it does not impose the production wrapper's UTF-8/content-type policy.

Output includes `accepted`, structured `error`, `output`, `events`,
`diagnostics`, mode/recovery flags, and explicit adapter metadata.
`accepted` means the selected library function returned `Ok`; with recovery
it does **not** mean that the document is well-formed. SAX strict prefixes
contain only callbacks already delivered when the parser stopped. Open
containers are closed by the adapter. Strict DOM errors expose no tree, so
`output` is null. DOM recovery output is the library's recovered tree and may
include data after a syntax error. SAX `events` are untouched callback
arguments, including full attribute tuples. DOM `events` are an explicit
preorder traversal of actual node kinds, including entity-reference wrappers
and their parsed children; they are not native SAX events. Recording depth
never filters these diagnostic events.

## Adapter behavior and limits

- Element/attribute qualified names are reconstructed from namespace prefix
  and local name. Attributes use `@name` fields in the first child map; first
  duplicate key wins in adapted output. Native events retain duplicates.
- Adjacent text/CDATA is coalesced across comments, PIs and entity wrappers.
  XML whitespace-only text runs are omitted unless a CDATA section or a DOM
  entity wrapper marks the run explicit. Callbacks/text nodes do not preserve
  numeric/predefined-reference provenance: `<r>&#32;</r>` currently adapts to
  `{"r":[]}` while native events/tree retain a space. This is an adapter
  approximation, not a library parsing failure. Native SAX also loses entity
  provenance; do not infer matching whitespace semantics from adapted output.
- `max_depth=0` records root attributes and suppresses root content;
  `max_depth=1` includes root content and direct child names/attributes while
  suppressing their content. All suppressed content is still parsed by the
  library. This differs from production's skipped suppressed entity expansion.
- The adapter does not repair parser input, invent truncated start elements,
  retain unreported text on fatal errors, or patch DTD/namespace behavior.
- Parser `recover` is selected explicitly, `no_blanks=false`,
  `max_depth=256`, `max_entity_expansions=256`, and `entity_resolver=None`.
  `--entity-limit N` changes the expansion cap. The cap counts references,
  including character/predefined references; it is different from production's
  byte budget. Other options retain library defaults. SAX source does not
  enforce every advertised `ParseOptions` size field; the adapter imposes a
  separate 1 MiB input cap, reported as an adapter error with `accepted=null`.
- No external resolver is installed, and the adapter never opens identifiers.
  External entity use rejects in strict mode; library recovery can omit it.
- Rust panics are caught per input and reported as `library_panic` with
  `accepted=null`. Stack overflow/abort/OOM cannot be caught. Run adversarial
  cases in separate processes with timeout/memory limits in the corpus runner.
- String input calls the options APIs directly. Base64 input adds the native
  decoder first. `Document::parse_bytes` is a different convenience API: it
  additionally strips one U+FEFF after decoding and offers no options argument.
  This harness does not silently emulate that additional convenience behavior.

## Verified native API differences

These differences were checked with direct API probes and the corpus runner
in the parent directory. The runner retains native events alongside adapted
body values so parsing and adapter behavior can be inspected separately.

- DTD default attribute `<r a="v">`: DOM applies the default; SAX omits it.
- General entity containing `<x>tail</x>`: DOM parses and exposes child nodes
  under an `EntityRef`; SAX emits the markup as plain character data.
- Empty string: strict SAX returns `Ok` without a root; strict DOM rejects
  `missing root element`.
- Recursive DTD entity: strict DOM rejects during DTD parsing; SAX ignores the
  DTD parse error and subsequently rejects an unknown entity on use.
- Literal `]]>`: strict SAX rejects before delivering its accumulated preceding
  character data. The adapter does not manufacture that missing prefix.

## Upstream source audit

The following source locations refer to the published xmloxide 0.5.0 crate:

- `src/sax/mod.rs:115` — `parse_sax` public API.
- `src/sax/mod.rs:148` — option wiring (depth/name/entity count/resolver).
- `src/sax/mod.rs:164` — SAX document parse sequence; optional root.
- `src/sax/mod.rs:292` — DTD subset parse, only entities imported; `if let Ok`
  ignores DTD parse failure. There is no ATTLIST application on SAX path.
- `src/sax/mod.rs:333` — element parsing and native attribute tuples.
- `src/sax/mod.rs:466` — character-data aggregation and references appended to
  one string; `characters` occurs only at the end of that text run.
- `src/parser/input.rs:1190` — `parse_reference_into` reference expansion.
- `src/parser/input.rs:1301` — `expand_entity_text`, returning expanded string
  rather than reparsing included markup on the SAX text path.
- `src/parser/xml.rs:333` and `:436` — DOM default attributes.
- `src/parser/xml.rs:1047` — DOM general entity references delegated to
  `parse_entity_ref_in_content`.
- `src/parser/xml.rs:1184` — DOM replacement parsing and child-node grafting.
- `src/parser/mod.rs:68` — `ParseOptions`; `:240` options-aware DOM API.
- `src/encoding/mod.rs:168` — bytes decoding.
- `src/tree/mod.rs:294` — `Document::parse_bytes` convenience behavior.

Public package: https://crates.io/crates/xmloxide/0.5.0
Versioned source: https://docs.rs/crate/xmloxide/0.5.0/source/src/sax/mod.rs
