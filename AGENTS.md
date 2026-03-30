General instructions:
- General building and testing instructions can be found in CONTRIBUTING.md (READ it!)
- When executing command via docker exec in a docker container, wrap into `bash -c` to ensure the environment is initialized correctly. Don't use absolute paths inside. Your work directory is dd-trace-php.

THINGS YOU PARTICULARLY WATCH FOR:

- Network I/O happening in PHP userland should go through the sidecar.
- Aggregation, global limits etc. should be orchestrated via shared memory / sidecar (perferably shared memory if feasible).
- Make sure to distinguish persistent memory (pemalloc) vs request memory (emalloc)
- Avoid unnecessary conversions (e.g. CString/String conversions in Rust FFI code - prefer CharSlice).
- When changing PHP code, make sure it works on every version. Rely mostly on compatibility.h vs creating manual #if's.
- Pay attention when adding globals - PHP can run in threaded mode. Thread-locals belong in DDTRACE_G().
- OpenTelemetry integration must be minimally invasive and integrate well with the extension state.
- Prefer using utilities from php-src over 

WHAT NOT TO DO:

- Do not edit to generated files automatically.
- Do not introduce latency on the user's request path.
- Avoid duplicated code as reasonable.
- Avoid test-only internal APIs be used as production interfaces (instead of e.g. dd_trace_internal_fn add proper APIs in ddtrace.stub.php)
- Never introduce blocking calls to the sidecar to resolve race conditions. Find other solutions.
