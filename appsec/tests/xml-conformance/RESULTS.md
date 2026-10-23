# xmloxide 0.5.0 evaluation

Evaluated on 2026-10-23 against production parser commit `6a1dae629`.
All existing tests are retained, including the literal `]]>` fix committed
immediately before this comparison. That fix passed 48 Rust body-parser tests
and all 26 XML PHPTs on PHP 8.5.10 debug.

The pinned candidate was tested through both public parsing implementations,
with recovery disabled and enabled. All 1,872 invocations completed without
timeouts, process failures, caught panics, or adapter input errors.
The preserved smoke script also passed 60 API/adapter probes.

## Compatibility counts

| API | Approved body values matching | Proposed body values matching | Outstanding XML 1.0 acceptance expectations matching |
| --- | ---: | ---: | ---: |
| SAX, strict | 141 / 255 | 30 / 52 | 110 / 125 |
| DOM, strict | 147 / 255 | 27 / 52 | 116 / 125 |
| SAX, recovery | 138 / 255 | 27 / 52 | Not a well-formedness check |
| DOM, recovery | 133 / 255 | 22 / 52 | Not a well-formedness check |

These are compatibility measurements, not W3C conformance scores. Thirty-six
Content-Type wrapper cases are run but excluded from native output comparison.
The 114 outstanding source cases become 125 runs through depth variants.
Namespace enforcement, external-resource restrictions, supported encodings,
expansion limits, and application recovery policies explain some differences.
The body adapter's incomplete reference-whitespace provenance also affects
output comparisons. All native callbacks/tree records remain in the generated
`results/results.jsonl` for inspection.

## Confirmed DTD/parser defects

### SAX omits attribute defaults

```xml
<!DOCTYPE r [<!ATTLIST r a CDATA "v">]><r/>
```

Expected body: `{"r":[{"@a":"v"}]}`. Strict SAX emits no attributes and produces
`{"r":[]}`. Strict DOM correctly supplies the default. The SAX DTD path imports
entity declarations only, and ignores errors returned by DTD subset parsing.
There is no option enabling its missing ATTLIST processing.

### SAX treats entity markup as character data

```xml
<!DOCTYPE r [<!ENTITY e "<x>tail</x>">]><r>&e;</r>
```

Expected body: `{"r":[{"x":["tail"]}]}`. Strict SAX emits the literal string
`<x>tail</x>`. Strict DOM correctly reparses the replacement into element nodes.
The adapter retains the actual SAX callback arguments; this difference occurs
inside the library before conversion to the body shape.

### Both APIs reject an escaped less-than sign in an attribute entity

```xml
<!DOCTYPE r [<!ENTITY e "&lt;">]><r a="&e;"/>
```

Expat 2.6.3, Expat 2.7.3, and libxml 2.9.13 accept this and produce `a="<"`.
Both strict xmloxide APIs reject it with:

```text
'<' not allowed in attribute values (from entity expansion)
```

The shared attribute implementation checks fully expanded text, losing the
distinction between a literal less-than sign and an escaped one. XML 1.0
appendix D explicitly identifies this form as well-formed.
[Specification example](https://www.w3.org/TR/xml/#sec-entexpand).

### Both APIs accept forbidden literal text in a used entity

```xml
<!DOCTYPE r [<!ENTITY e "prefix]]>after">]>
<r>before<x>&e;</x><tail>visible</tail></r>
```

Both strict APIs accept this at recording depths 1 and 30. Expat and libxml
reject it. The DOM replacement fast path bypasses content parsing for text
without `<`; the SAX entity validator has a similar shortcut. Literal `]]>` is
forbidden by CharData, including in parsed entity replacement text.
[Parsed entities](https://www.w3.org/TR/xml/#wf-entities),
[character data](https://www.w3.org/TR/xml/#syntax).

### DOM accepts an undeclared entity despite standalone="yes"

```xml
<?xml version="1.0" standalone="yes"?>
<!DOCTYPE r SYSTEM "file:///not-read"><r>&e;</r>
```

Strict DOM accepts; strict SAX, Expat, and libxml reject. No external resolver
is installed. With standalone="yes", Entity Declared is a well-formedness
constraint even when the document names an external subset.
[Entity Declared](https://www.w3.org/TR/xml/#wf-entdeclared).

The corpus also exposes SAX acceptance of U+FFFE/U+FFFF, an empty document,
duplicate attributes, and several malformed DTD declarations. Inspect strict
acceptance mismatches in the generated results for their exact inputs.

## Recovery and integration

Strict SAX offers a useful callback prefix, but may withhold text accumulated
before a syntax error. Strict DOM exposes no partial tree on failure.
Recovery mode returns trees or callbacks after some fatal errors, conflicting
with the approved stop-at-error behavior. These are integration concerns in
addition to the confirmed parsing defects above.

xmloxide 0.5.0 is therefore not a compatible replacement for this converter.
The corpus and isolated runner can evaluate future versions or another parser;
the production dependency remains unchanged.

Versioned upstream source locations and adapter limitations are documented in
[the harness README](xmloxide/README.md). Reproduce with:

```sh
uv run appsec/tests/xml-conformance/run.py
```
