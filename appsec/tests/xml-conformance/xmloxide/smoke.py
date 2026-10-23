# /// script
# requires-python = ">=3.11"
# ///
import base64
import json
from pathlib import Path
import subprocess

base = Path(__file__).resolve().parent
binary = base / "target/release/xmloxide-body-harness"
cases = [
    {"name": "simple", "xml": '<r a="v">a<!-- c -->b<?p info?><x/>c<![CDATA[d]]></r>'},
    {
        "name": "depth",
        "xml": '<r>before<x a="v">hidden<y/></x><tail>visible</tail></r>',
        "max_depth": 1,
    },
    {"name": "literal-space", "xml": "<r> <x/> </r>"},
    {"name": "cdata-space", "xml": "<r><![CDATA[ ]]></r>"},
    {"name": "reference-space", "xml": "<r>&#32;</r>"},
    {"name": "fatal-pi", "xml": "<r>before<?XML info?><x>tail</x></r>"},
    {"name": "fatal-cdata-close", "xml": "<r>before]]><x>tail</x></r>"},
    {"name": "default-attribute", "xml": '<!DOCTYPE r [<!ATTLIST r a CDATA "v">]><r/>'},
    {
        "name": "entity-markup",
        "xml": '<!DOCTYPE r [<!ENTITY e "<x>tail</x>">]><r>before&e;after</r>',
    },
    {
        "name": "external-entity",
        "xml": '<!DOCTYPE r [<!ENTITY e SYSTEM "file:///etc/passwd">]><r>&e;</r>',
    },
    {"name": "recursive-entity", "xml": '<!DOCTYPE r [<!ENTITY e "&e;">]><r>&e;</r>'},
    {"name": "truncated", "xml": "<r>before<x"},
    {"name": "empty", "xml": ""},
    {"name": "invalid-utf8", "xml_base64": base64.b64encode(b"<r>a\xff</r>").decode()},
    {
        "name": "latin1-bytes",
        "xml_base64": base64.b64encode(
            b'<?xml version="1.0" encoding="ISO-8859-1"?><r>\xe9</r>'
        ).decode(),
    },
]
(base / "smoke-input.jsonl").write_text("".join(json.dumps(c) + "\n" for c in cases))
rows = []
for mode, args in [
    ("sax-strict", []),
    ("sax-recover", ["--recover"]),
    ("dom-strict", ["--dom"]),
    ("dom-recover", ["--dom", "--recover"]),
]:
    mode_rows = []
    for case in cases:
        result = subprocess.run(
            [str(binary), *args],
            input=json.dumps(case) + "\n",
            text=True,
            capture_output=True,
            timeout=10,
        )
        assert result.returncode == 0, (mode, case["name"], result.stderr)
        row = json.loads(result.stdout)
        assert row["name"] == case["name"]
        assert not row.get("library_panic"), (mode, row)
        mode_rows.append(row)
        rows.append((mode, row))
    (base / f"smoke-{mode}.jsonl").write_text(
        "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in mode_rows)
    )
index = {(mode, row["name"]): row for mode, row in rows}
for mode in ["sax-strict", "sax-recover", "dom-strict", "dom-recover"]:
    assert index[mode, "simple"]["output"] == {
        "r": [{"@a": "v"}, "ab", {"x": []}, "cd"]
    }
    assert index[mode, "depth"]["output"] == {
        "r": ["before", {"x": [{"@a": "v"}]}, {"tail": []}]
    }
    assert index[mode, "literal-space"]["output"] == {"r": [{"x": []}]}
    assert index[mode, "cdata-space"]["output"] == {"r": [" "]}
    assert index[mode, "invalid-utf8"]["accepted"] is False
assert index["dom-strict", "entity-markup"]["output"] == {
    "r": ["before", {"x": ["tail"]}, "after"]
}
assert index["sax-strict", "entity-markup"]["output"] == {
    "r": ["before<x>tail</x>after"]
}
assert index["dom-strict", "default-attribute"]["output"] == {"r": [{"@a": "v"}]}
assert index["sax-strict", "default-attribute"]["output"] == {"r": []}
assert index["sax-strict", "fatal-pi"]["output"] == {"r": ["before"]}
assert index["dom-strict", "fatal-pi"]["output"] is None
summary = [
    {
        "name": case["name"],
        **{
            mode: {
                "accepted": index[mode, case["name"]]["accepted"],
                "output": index[mode, case["name"]]["output"],
                "error": index[mode, case["name"]]["error"],
            }
            for mode in ["sax-strict", "sax-recover", "dom-strict", "dom-recover"]
        },
    }
    for case in cases
]
(base / "smoke-summary.json").write_text(
    json.dumps(summary, indent=2, ensure_ascii=False) + "\n"
)
print(
    f"{len(rows)} mode/case probes passed; 4 adapter behaviors checked per mode, encoding rejection checked, strict SAX/DOM prefix distinction checked."
)
for name in [
    "reference-space",
    "default-attribute",
    "entity-markup",
    "recursive-entity",
    "empty",
]:
    row = next(r for r in summary if r["name"] == name)
    print(json.dumps(row, ensure_ascii=False))
