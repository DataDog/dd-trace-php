# Cross-SDK consistency

Behaviour that must match other Datadog SDKs, specs (including OpenTelemetry),
and the agent or backend contract.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line, naming the spec, registry, or contract you compared against. Only the
cross-SDK reviewer may consult external references.

- Check changes to shared tracer behavior (e.g., context propagation,
  sampling, configuration, or telemetry) for cross-language impact, using
  relevant specifications and other tracer implementations as references.
- When an issue stems from the shared design or specification, explicitly
  flag it as a **design issue** requiring coordination with the affected
  tracer teams.
- Explain why a PHP-only fix is insufficient or would create divergence.
- Consider whether functionality should be implemented in libdatadog to
  enforce cross-SDK consistency.
- Distinguish this from a PHP implementation bug that can be fixed locally to
  match the agreed behavior.
- Validation across all dd-trace- repositories is not required.

Other repositories:
- [.NET (`dd-trace-dotnet`)](https://github.com/DataDog/dd-trace-dotnet)
- [Java (`dd-trace-java`)](https://github.com/DataDog/dd-trace-java)
- [Python (`dd-trace-py`)](https://github.com/DataDog/dd-trace-py)
- [Node.js (`dd-trace-js`)](https://github.com/DataDog/dd-trace-js)
- [Go (`dd-trace-go`)](https://github.com/DataDog/dd-trace-go)
- [Ruby (`dd-trace-rb`)](https://github.com/DataDog/dd-trace-rb)
- [C++ (`dd-trace-cpp`)](https://github.com/DataDog/dd-trace-cpp)
- [Rust (`libdatadog`)](https://github.com/DataDog/libdatadog)

## 1. Agent and backend contract

### XSDK-1.1: Match the agent's definition when computing span-stats fields
- Flag: `top_level`/measured marking that differs from the agent's
  `traceutil` definition (service-entry spans, chunk-aware, ignoring
  already-submitted spans).

## 2. Specs and shared formats

### XSDK-2.1: Follow the W3C/OTel tracestate format exactly
- Flag: variable-width `rv`/`th` values, or an owned `ot` member counted
  against the vendor limit instead of handled like `dd`.
- ❌ `smart_str_append_printf(&s, "rv:%" PRIx64, rv);`
- ✅ `smart_str_append_printf(&s, "rv:%014" PRIx64, rv);`

### XSDK-2.2: Keep shared patterns language-agnostic
- Flag: PHP-specific changes to a pattern shared across SDKs (e.g. the
  query-string obfuscation regex default in `configuration.h`).

### XSDK-2.3: Send V1 attributes with native types
- Flag: arrays/objects JSON-encoded, or numbers/booleans stringified, when
  writing V1 attributes.
- Not when: the backend relies on the key being a string.

### XSDK-2.4: Keep established sentinel values in shared formats
- Flag: replacing deliberate sentinels (e.g. negative crashtracker frame
  columns for unmapped frames) with values that collide with "unknown".

## 3. Consistent values across signals

### XSDK-3.1: Use the same resolution logic for a value in every signal
- Flag: a value resolved differently than on spans (e.g. publishing only
  `DD_HOSTNAME` where spans fall back to `gethostname()`).

### XSDK-3.2: Propagate root metadata to every span that carries it
- Flag: new root meta (e.g. `_dd.svc_src`) not copied to inferred proxy
  spans, or with no value for default service names (`web.request`, CLI
  entrypoint).
