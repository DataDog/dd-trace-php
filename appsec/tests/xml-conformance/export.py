# /// script
# requires-python = ">=3.11"
# ///
"""Capture PHPT inputs without loading ddtrace or invoking an XML parser."""

import argparse
import base64
import hashlib
import json
import pathlib
import re
import subprocess

HERE = pathlib.Path(__file__).resolve().parent
cli = argparse.ArgumentParser(description=__doc__)
cli.add_argument("--repo-source", type=pathlib.Path, default=HERE / "sources/repo")
cli.add_argument(
    "--proposed-source", type=pathlib.Path, default=HERE / "sources/proposed"
)
cli.add_argument("--output", type=pathlib.Path, default=HERE)
cli.add_argument("--php", default="php")
args = cli.parse_args()
OUT = args.output.resolve()
OUT.mkdir(parents=True, exist_ok=True)
GROUPS = {
    "repo": args.repo_source.resolve(),
    "proposed": args.proposed_source.resolve(),
}
STUB = """<?php
namespace datadog\\appsec {
function convert_xml($xml, $content_type) {
    $i = $GLOBALS['capture_index']++;
    $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $record = ['call_index' => $i, 'xml_base64' => base64_encode($xml),
      'content_type_base64' => base64_encode($content_type),
      'source_line' => $frame['line'], 'runtime_case_name' => $GLOBALS['name'] ?? null];
    file_put_contents($GLOBALS['capture_path'], json_encode($record) . "\\n", FILE_APPEND);
    return $GLOBALS['capture_expected'][$i];
}
}
namespace {
if (extension_loaded('ddtrace')) throw new \\RuntimeException('ddtrace must not be loaded');
$GLOBALS['capture_index'] = 0;
$GLOBALS['capture_path'] = $argv[2];
$GLOBALS['capture_expected'] = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
include $argv[1];
}
"""
(OUT / "capture.php").write_text(STUB)


def sections(raw):
    hits = list(re.finditer(rb"^--([A-Z_]+)--\r?\n", raw, re.M))
    return {
        h[1].decode(): raw[
            h.end() : hits[i + 1].start() if i + 1 < len(hits) else len(raw)
        ]
        for i, h in enumerate(hits)
    }


def expected_values(raw):
    text = raw.decode("utf-8")
    decoder = json.JSONDecoder()
    values = []
    spans = []
    for m in re.finditer(
        r"(?m)^(?:(?P<label>[^\n]*?): )?(?P<start>\{|\[|null\b|NULL\b)", text
    ):
        pos = m.start("start")
        if any(a <= pos < b for a, b in spans):
            continue
        if text.startswith("NULL", pos):
            value = None
            end = pos + 4
        else:
            try:
                value, n = decoder.raw_decode(text[pos:])
                end = pos + n
            except ValueError:
                continue
        values.append(
            {"value": value, "label": m.group("label"), "source_text": text[pos:end]}
        )
        spans.append((pos, end))
    return values


cases = []
tests = []
for group, folder in GROUPS.items():
    for source in sorted(folder.glob("convert_xml_*.phpt")):
        raw = source.read_bytes()
        sec = sections(raw)
        expect_kind = next(k for k in ("EXPECT", "EXPECTF", "EXPECTREGEX") if k in sec)
        expected = expected_values(sec[expect_kind])
        assert expected, (source, "no expected values")
        dest = OUT / "sources" / group / source.name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(raw)
        work = OUT / "runtime" / group / source.stem
        work.mkdir(parents=True, exist_ok=True)
        (work / "test.php").write_bytes(sec["FILE"])
        (work / "expected.json").write_text(json.dumps([e["value"] for e in expected]))
        capture = work / "capture.jsonl"
        capture.unlink(missing_ok=True)
        run = subprocess.run(
            [
                args.php,
                "-n",
                str(OUT / "capture.php"),
                str(work / "test.php"),
                str(capture),
                str(work / "expected.json"),
            ],
            capture_output=True,
        )
        assert run.returncode == 0, (source, run.stderr.decode())
        (work / "stdout.txt").write_bytes(run.stdout)
        (work / "stderr.txt").write_bytes(run.stderr)
        records = [json.loads(s) for s in capture.read_text().splitlines()]
        assert len(records) == len(expected), (source, len(records), len(expected))
        test_id = f"{group}/{source.name}"
        ini = sec.get("INI", b"").decode().strip()
        test = {
            "id": test_id,
            "source_path": f"sources/{group}/{source.name}",
            "snapshot": str(dest.relative_to(OUT)),
            "sha256": hashlib.sha256(raw).hexdigest(),
            "expect_kind": expect_kind,
            "expected_output": sec[expect_kind].decode(),
            "ini": ini,
            "case_count": len(records),
            "authority": "approved_repository_expectation"
            if group == "repo"
            else "unapproved_proposed_expectation",
        }
        expected_rendered = sec[expect_kind].decode().strip()
        if source.name == "convert_xml_iso88591.phpt":
            expected_rendered = expected_rendered.split("\n", 1)[1].strip()
            test["replay_note"] = (
                "Stub omits extension-generated unsupported-charset notice; full EXPECTF preserved."
            )
        test["expected_mapping_replay_verified"] = (
            run.stdout.decode().strip() == expected_rendered
        )
        assert test["expected_mapping_replay_verified"], (
            source,
            "expectation mapping replay failed",
        )
        tests.append(test)
        for r, e in zip(records, expected):
            xml = base64.b64decode(r["xml_base64"])
            ct = base64.b64decode(r["content_type_base64"])
            try:
                input_value = {"encoding": "utf-8", "data": xml.decode("utf-8")}
            except UnicodeDecodeError:
                input_value = {"encoding": "base64", "data": r["xml_base64"]}
            label = (
                e["label"] or r["runtime_case_name"] or f"call-{r['call_index'] + 1}"
            )
            cases.append(
                {
                    "id": f"{test_id}::{r['call_index'] + 1}:{label}",
                    "origin_test": test_id,
                    "origin_case": label,
                    "origin_call_index": r["call_index"],
                    "origin_php_line": r["source_line"],
                    "authority": test["authority"],
                    "xml": input_value,
                    "xml_byte_length": len(xml),
                    "xml_sha256": hashlib.sha256(xml).hexdigest(),
                    "content_type": ct.decode("utf-8"),
                    "max_depth": 30,
                    "max_depth_source": "appsec/src/extension/entity_body.c: MAX_XML_DEPTH = 30 (not configurable by these PHPTs)",
                    "expected": {
                        "derivation": "ordered JSON/NULL result in PHPT expectation",
                        "value": e["value"],
                        "source_text": e["source_text"],
                    },
                }
            )
(OUT / "cases.jsonl").write_text(
    "".join(json.dumps(c, ensure_ascii=False) + "\n" for c in cases)
)
(OUT / "tests.json").write_text(json.dumps(tests, indent=2, ensure_ascii=False) + "\n")
summary = {
    "schema_version": 1,
    "tests": len(tests),
    "cases": len(cases),
    "by_authority": {
        a: sum(c["authority"] == a for c in cases)
        for a in sorted({c["authority"] for c in cases})
    },
    "base64_cases": sum(c["xml"]["encoding"] == "base64" for c in cases),
    "all_expected_values_derived": True,
    "all_expected_mappings_replay_verified": all(
        t["expected_mapping_replay_verified"] for t in tests
    ),
    "capture_php_version": subprocess.check_output(
        [args.php, "-n", "-r", "echo PHP_VERSION;"]
    ).decode(),
}
(OUT / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
print(json.dumps(summary, indent=2))
