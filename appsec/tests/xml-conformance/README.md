# XML parser comparison corpus

This directory preserves the tests from the XML/DTD review and evaluates
xmloxide 0.5.0 and an anyxml 0.15.1 SAX body-parser prototype without changing
the production parser dependency.

The [anyxml prototype](anyxml/README.md) writes the real production tape and
compares it with the current parser. Run its SAX-only comparison with:

```sh
uv run appsec/tests/xml-conformance/run.py --parser anyxml \
  --output appsec/tests/xml-conformance/results/anyxml
uv run appsec/tests/xml-conformance/run.py --parser current \
  --output appsec/tests/xml-conformance/results/current
```

The corpus contains 457 source cases:

- 282 calls from the 26 approved `convert_xml_*.phpt` tests.
- 61 calls from 15 proposed PHPTs, including issues not yet resolved.
- 114 additional cases for outstanding findings and valid controls, annotated
  with XML 1.0 expectations and Expat/libxml observations.

Eleven outstanding cases also run with a recording depth of one, producing
468 configurations. The original xmloxide evaluation runs each through strict
SAX, strict DOM, recovering SAX, and recovering DOM: 1,872 parser invocations.
The anyxml prototype and current-parser baseline each run 468 configurations
through the body interface. Original PHPT snapshots, exact
input bytes, expectations, and provenance remain in `sources/`, `cases.jsonl`,
and `tests.json`. Proposed recovery expectations remain proposals; they do not
silently change the approved behavior of the production parser.

## Run

From the repository root:

```sh
uv run appsec/tests/xml-conformance/run.py
```

This builds the isolated Rust harness using its pinned Cargo.lock. Each input
runs in its own process with a ten-second timeout. Four workers run by default.
Raw results, native events, adapted values, and summary counts are written to
the ignored `results/` directory. Differences are reported rather than causing
a nonzero exit: this is an evaluation runner, not a production regression gate.

Use `--case REGEX` or `--modes sax dom` to narrow the xmloxide comparison.
`--binary PATH` selects an already-built harness. It disables external-resource
loading and bounds input size, parser depth, and entity expansions.

To regenerate the captured PHPT arguments from the retained snapshots:

```sh
uv run appsec/tests/xml-conformance/export.py
```

PHP is required only for regeneration. The exporter replays each original
FILE section with a recording stub and verifies its expected output mapping.
It does not call an XML parser. See [CORPUS.md](CORPUS.md) for the schema and
[xmloxide/README.md](xmloxide/README.md) for the native APIs and adapter limits.

## Interpreting results

Output compatibility and XML acceptance are different measurements. Existing
PHPTs expose body values but not parser completion status. Their output checks
include deliberate truncation recovery and unresolved-reference policies.
Outstanding cases additionally test native acceptance against XML 1.0 rules.

Thirty-six charset-wrapper configurations are retained and run, but excluded
from native-parser output comparisons: Content-Type processing is outside the
candidate parser. Namespace restrictions, configured expansion limits, and
unsupported external entities can also explain acceptance differences.

The xmloxide adapter reconstructs the existing body shape and coalesces text.
Native callbacks and DOM nodes do not retain all character-reference provenance,
so some whitespace differences belong to the adapter. Strict DOM errors expose
no partial tree. Recovery mode can return content after a fatal error, which
differs from the production parser's chosen stopping policy.

See [RESULTS.md](RESULTS.md) for the recorded evaluation and concrete findings.
