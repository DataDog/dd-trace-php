#!/usr/bin/env python3

import codecs
import json
import os
import re
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = REPO_ROOT / "ext" / "configuration.h"
INTEGRATIONS_PATH = REPO_ROOT / "ext" / "integrations" / "integrations.h"
OUTPUT_PATH = REPO_ROOT / "metadata" / "supported-configurations.json"


def read_file(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except OSError:
        print(f"Error: failed to read {path}", file=sys.stderr)
        raise SystemExit(1)


def normalize_line_endings(contents: str) -> str:
    return contents.replace("\r\n", "\n")


def strip_line_continuations(contents: str) -> str:
    return re.sub(r"\\\r?\n", "", contents)


def eval_condition(expr: str, defines: dict, defined: set) -> bool:
    expr = expr.strip()
    if not expr:
        return False

    expr = re.sub(
        r"\bdefined\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)",
        lambda m: "1" if m.group(1) in defined else "0",
        expr,
    )
    expr = expr.replace("&&", " and ").replace("||", " or ")
    expr = re.sub(r"!\s*(?!=)", " not ", expr)

    def replace_ident(match: re.Match) -> str:
        name = match.group(0)
        if name in ("and", "or", "not"):
            return name
        if name == "true":
            return "1"
        if name == "false":
            return "0"
        if name in defines:
            value = str(defines[name]).strip()
            if value == "true":
                return "1"
            if value == "false":
                return "0"
            if re.fullmatch(r"-?\d+", value):
                return value
        return "0"

    expr = re.sub(r"\b[A-Za-z_][A-Za-z0-9_]*\b", replace_ident, expr)

    try:
        return bool(eval(expr, {"__builtins__": {}}, {}))
    except Exception:
        return False


def preprocess_defines(contents: str, predefined_defines: dict, predefined_defined: set):
    defines = dict(predefined_defines)
    defined = set(predefined_defined)
    macro_bodies = {}

    lines = normalize_line_endings(strip_line_continuations(contents)).split("\n")
    stack = []
    active = True

    for line in lines:
        trimmed = line.strip()
        if not trimmed:
            continue

        if re.match(r"^#\s*if\s+", trimmed):
            condition = re.sub(r"^#\s*if\s+", "", trimmed)
            cond_value = eval_condition(condition, defines, defined)
            stack.append({"parent_active": active, "matched": cond_value, "active": cond_value})
            active = active and cond_value
            continue

        match = re.match(r"^#\s*ifdef\s+([A-Za-z_][A-Za-z0-9_]*)$", trimmed)
        if match:
            cond_value = match.group(1) in defined
            stack.append({"parent_active": active, "matched": cond_value, "active": cond_value})
            active = active and cond_value
            continue

        match = re.match(r"^#\s*ifndef\s+([A-Za-z_][A-Za-z0-9_]*)$", trimmed)
        if match:
            cond_value = match.group(1) not in defined
            stack.append({"parent_active": active, "matched": cond_value, "active": cond_value})
            active = active and cond_value
            continue

        match = re.match(r"^#\s*elif\s+", trimmed)
        if match and stack:
            condition = re.sub(r"^#\s*elif\s+", "", trimmed)
            state = stack.pop()
            if not state["parent_active"] or state["matched"]:
                state["active"] = False
            else:
                cond_value = eval_condition(condition, defines, defined)
                state["active"] = cond_value
                state["matched"] = cond_value
            stack.append(state)
            active = state["parent_active"] and state["active"]
            continue

        if re.match(r"^#\s*else\b", trimmed):
            if stack:
                state = stack.pop()
                if not state["parent_active"] or state["matched"]:
                    state["active"] = False
                else:
                    state["active"] = True
                    state["matched"] = True
                stack.append(state)
                active = state["parent_active"] and state["active"]
            continue

        if re.match(r"^#\s*endif\b", trimmed):
            if stack:
                state = stack.pop()
                active = state["parent_active"]
            continue

        if not active:
            continue

        if re.match(r"^#\s*define\s+[A-Za-z_][A-Za-z0-9_]*\(", trimmed):
            continue

        match = re.match(r"^#\s*define\s+(DD_CONFIGURATION(?:_ALL)?)\s+(.*)$", trimmed)
        if match:
            macro_bodies[match.group(1)] = match.group(2).strip()
            continue

        match = re.match(r"^#\s*define\s+([A-Za-z_][A-Za-z0-9_]*)\s+(.*)$", trimmed)
        if match:
            name = match.group(1)
            if not name.startswith("DD_CONFIGURATION"):
                defines[name] = match.group(2).strip()
                defined.add(name)
            continue

        match = re.match(r"^#\s*undef\s+([A-Za-z_][A-Za-z0-9_]*)$", trimmed)
        if match:
            name = match.group(1)
            defines.pop(name, None)
            defined.discard(name)

    return defines, defined, macro_bodies


def extract_macro_calls(body: str, call_name: str) -> list:
    calls = []
    needle = f"{call_name}("
    length = len(body)
    offset = 0

    while True:
        pos = body.find(needle, offset)
        if pos == -1:
            break
        i = pos + len(needle)
        depth = 1
        in_string = False
        escape = False
        while i < length and depth > 0:
            ch = body[i]
            if in_string:
                if escape:
                    escape = False
                elif ch == "\\":
                    escape = True
                elif ch == '"':
                    in_string = False
            else:
                if ch == '"':
                    in_string = True
                elif ch == "(":
                    depth += 1
                elif ch == ")":
                    depth -= 1
            i += 1
        if depth != 0:
            break
        calls.append(body[pos + len(needle) : i - 1])
        offset = i

    return calls


def split_args(arg_string: str) -> list:
    args = []
    current = []
    depth = 0
    in_string = False
    escape = False

    for ch in arg_string:
        if in_string:
            current.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            continue

        if ch == '"':
            in_string = True
            current.append(ch)
            continue

        if ch == "(":
            depth += 1
            current.append(ch)
            continue

        if ch == ")":
            depth = max(0, depth - 1)
            current.append(ch)
            continue

        if ch == "," and depth == 0:
            args.append("".join(current).strip())
            current = []
            continue

        current.append(ch)

    tail = "".join(current).strip()
    if tail:
        args.append(tail)

    return args


def unescape_c_string(token: str):
    token = token.strip()
    if len(token) < 2 or token[0] != '"' or token[-1] != '"':
        return None
    inner = token[1:-1]
    try:
        return codecs.decode(inner, "unicode_escape")
    except Exception:
        return inner


def resolve_macro_value(name: str, defines: dict):
    if name not in defines:
        return None
    value = str(defines[name]).strip()
    string_value = unescape_c_string(value)
    return string_value if string_value is not None else value


def resolve_token_string(token: str, defines: dict):
    token = token.strip()
    if token in ("NULL", "null"):
        return None
    string_value = unescape_c_string(token)
    if string_value is not None:
        return string_value

    match = re.match(r"^DD_CFG_EXPSTR\((.+)\)$", token)
    if match:
        inner = match.group(1).strip()
        macro_value = resolve_macro_value(inner, defines)
        return macro_value if macro_value is not None else inner

    match = re.match(r"^DD_CFG_STR\((.+)\)$", token)
    if match:
        return match.group(1).strip()

    if token in defines:
        return resolve_macro_value(token, defines)

    return token


def resolve_default_token(token: str, defines: dict):
    value = resolve_token_string(token, defines)
    if value is None:
        return None
    return str(value)


def resolve_alias_token(token: str, defines: dict):
    value = resolve_token_string(token, defines)
    if value is None:
        return None
    return str(value)


def normalize_aliases(aliases: list, canonical: str) -> list:
    filtered = {}
    for alias in aliases:
        if alias is None:
            continue
        alias = alias.strip()
        if not alias or alias == canonical:
            continue
        filtered[alias] = True
    result = sorted(filtered.keys())
    return result


def map_type(type_name: str) -> str:
    type_name = type_name.strip()
    match = re.match(r"^CUSTOM\((.+)\)$", type_name)
    if match:
        type_name = match.group(1).strip()

    if type_name == "BOOL":
        return "boolean"
    if type_name == "STRING":
        return "string"
    if type_name == "INT":
        return "int"
    if type_name == "DOUBLE":
        return "decimal"
    if type_name in ("MAP", "JSON", "SET_OR_MAP_LOWERCASE"):
        return "map"
    if type_name in ("SET", "SET_LOWERCASE"):
        return "array"
    return "string"


def entry_from_config(type_name: str, name: str, default_token: str, aliases: list, defines: dict) -> dict:
    entry = {
        "implementation": "A",
        "type": map_type(type_name),
        "default": resolve_default_token(default_token, defines),
    }
    normalized_aliases = normalize_aliases(aliases, name)
    if normalized_aliases:
        entry["aliases"] = normalized_aliases
    return entry


def parse_config_macro_entries(macro_body: str, defines: dict) -> dict:
    entries = {}
    for call_args in extract_macro_calls(macro_body, "CONFIG"):
        args = split_args(call_args)
        if len(args) < 3:
            continue
        type_name, name, default_token = args[0], args[1], args[2]
        entries[name] = entry_from_config(type_name, name, default_token, [], defines)

    for call_args in extract_macro_calls(macro_body, "CALIAS"):
        args = split_args(call_args)
        if len(args) < 4:
            continue
        type_name, name, default_token, aliases_arg = args[0], args[1], args[2], args[3]
        aliases = []
        match = re.match(r"^CALIASES\((.*)\)$", aliases_arg.strip())
        if match:
            for alias_token in split_args(match.group(1)):
                aliases.append(resolve_alias_token(alias_token, defines))
        entries[name] = entry_from_config(type_name, name, default_token, aliases, defines)

    return entries


def parse_integrations(defines: dict) -> dict:
    contents = read_file(INTEGRATIONS_PATH)
    contents = normalize_line_endings(strip_line_continuations(contents))
    macro_body = None

    for line in contents.split("\n"):
        trimmed = line.strip()
        match = re.match(r"^#\s*define\s+DD_INTEGRATIONS\s+(.*)$", trimmed)
        if match:
            macro_body = match.group(1).strip()
            break

    if macro_body is None:
        return {}

    integrations = {}
    for call_args in extract_macro_calls(macro_body, "INTEGRATION_CUSTOM_ENABLED"):
        args = split_args(call_args)
        if not args:
            continue
        integrations[args[0]] = [args[0]]

    for call_args in extract_macro_calls(macro_body, "INTEGRATION"):
        args = split_args(call_args)
        if not args:
            continue
        integrations[args[0]] = args

    entries = {}
    analytics_default = resolve_default_token(
        "DD_CFG_EXPSTR(DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT)", defines
    )
    sample_rate_default = resolve_default_token(
        "DD_CFG_EXPSTR(DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT)", defines
    )

    for integration_id, args in integrations.items():
        extra_args = args[1:]
        default_token = '"true"'
        aliases = []
        if len(extra_args) >= 2:
            default_token = extra_args[1]
            for extra in extra_args[2:]:
                match = re.match(r"^CALIASES\((.*)\)$", extra.strip())
                if match:
                    for alias_token in split_args(match.group(1)):
                        aliases.append(resolve_alias_token(alias_token, defines))

        name = f"DD_TRACE_{integration_id}_ENABLED"
        entries[name] = entry_from_config("BOOL", name, default_token, aliases, defines)

        analytics_name = f"DD_TRACE_{integration_id}_ANALYTICS_ENABLED"
        analytics_alias = f"DD_{integration_id}_ANALYTICS_ENABLED"
        entries[analytics_name] = entry_from_config(
            "BOOL", analytics_name, analytics_default, [analytics_alias], defines
        )

        sample_rate_name = f"DD_TRACE_{integration_id}_ANALYTICS_SAMPLE_RATE"
        sample_rate_alias = f"DD_{integration_id}_ANALYTICS_SAMPLE_RATE"
        entries[sample_rate_name] = entry_from_config(
            "DOUBLE", sample_rate_name, sample_rate_default, [sample_rate_alias], defines
        )

    return entries


def build_defines():
    defines = {}
    defined = set()

    php_version = os.getenv("PHP_VERSION_ID", "0").strip()
    if php_version:
        defines["PHP_VERSION_ID"] = php_version
        defined.add("PHP_VERSION_ID")

    if sys.platform.startswith("win") or os.getenv("DDTRACE_DEFINE__WIN32") == "1":
        defines["_WIN32"] = "1"
        defined.add("_WIN32")

    if os.getenv("DDTRACE_DEFINE__BUILD_FROM_PECL_") == "1":
        defines["_BUILD_FROM_PECL_"] = "1"
        defined.add("_BUILD_FROM_PECL_")

    if os.getenv("DDTRACE_DEFINE__SANITIZE_ADDRESS__") == "1":
        defines["__SANITIZE_ADDRESS__"] = "1"
        defined.add("__SANITIZE_ADDRESS__")

    return defines, defined


def merge_supported_configurations(output: dict, generated: dict):
    output.setdefault("supportedConfigurations", {})
    output.setdefault("deprecations", {})
    output.setdefault("version", "2")

    existing_supported = output.get("supportedConfigurations", {})
    new_supported = {}
    for name, entry_list in generated.items():
        generated_entry = entry_list[0]
        existing_entries = existing_supported.get(name, [])
        if not isinstance(existing_entries, list):
            existing_entries = []
        normalized_entries = []
        for existing_entry in existing_entries:
            if isinstance(existing_entry, dict) and "implementation" not in existing_entry and "version" in existing_entry:
                existing_entry["implementation"] = existing_entry.pop("version")
            normalized_entries.append(existing_entry)
        existing_entries = normalized_entries
        updated = False
        for idx, existing_entry in enumerate(existing_entries):
            if isinstance(existing_entry, dict) and existing_entry.get("implementation") == "A":
                existing_entries[idx] = generated_entry
                updated = True
                break
        if not updated:
            existing_entries.append(generated_entry)
        new_supported[name] = existing_entries

    output["supportedConfigurations"] = {k: new_supported[k] for k in sorted(new_supported)}

    for name, entries in output["supportedConfigurations"].items():
        if not isinstance(entries, list):
            continue
        for entry in entries:
            if isinstance(entry, dict) and isinstance(entry.get("aliases"), list):
                normalized_aliases = normalize_aliases(entry["aliases"], name)
                if normalized_aliases:
                    entry["aliases"] = normalized_aliases
                else:
                    entry.pop("aliases", None)


def main():
    try:
        if not CONFIG_PATH.exists():
            print(f"Error: configuration header not found at {CONFIG_PATH}", file=sys.stderr)
            return 1
        if not INTEGRATIONS_PATH.exists():
            print(f"Error: integrations header not found at {INTEGRATIONS_PATH}", file=sys.stderr)
            return 1

        config_contents = read_file(CONFIG_PATH)
        predefined_defines, predefined_defined = build_defines()
        defines, defined, macro_bodies = preprocess_defines(
            config_contents, predefined_defines, predefined_defined
        )

        if "DD_CONFIGURATION_ALL" not in macro_bodies and "DD_CONFIGURATION" not in macro_bodies:
            print("Error: no DD_CONFIGURATION macros found in configuration header", file=sys.stderr)
            return 1

        entries = {}
        if "DD_CONFIGURATION_ALL" in macro_bodies:
            entries.update(parse_config_macro_entries(macro_bodies["DD_CONFIGURATION_ALL"], defines))
        if "DD_CONFIGURATION" in macro_bodies:
            entries.update(parse_config_macro_entries(macro_bodies["DD_CONFIGURATION"], defines))

        entries.update(parse_integrations(defines))

        supported = {}
        for name, entry in entries.items():
            if not name.startswith("DD_"):
                continue
            supported[name] = [entry]

        if not supported:
            print("Error: no supported configurations were generated", file=sys.stderr)
            return 1

        sorted_supported = {k: supported[k] for k in sorted(supported)}
        output = {
            "version": "2",
            "supportedConfigurations": sorted_supported,
            "deprecations": {},
        }

        if OUTPUT_PATH.exists():
            try:
                existing = json.loads(OUTPUT_PATH.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                print(f"Error: existing {OUTPUT_PATH} is not valid JSON", file=sys.stderr)
                return 1
            if isinstance(existing, dict):
                output = existing
            merge_supported_configurations(output, sorted_supported)
        else:
            merge_supported_configurations(output, sorted_supported)

        OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
        json_text = json.dumps(output, indent=2)
        OUTPUT_PATH.write_text(json_text + "\n", encoding="utf-8")
        print(f"Wrote supported configurations to {OUTPUT_PATH}")
        return 0
    except OSError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
