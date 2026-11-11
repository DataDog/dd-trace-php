# Agentless Feature Flags configuration

Enable CDN configuration with `DD_FEATURE_FLAGS_ENABLED=true` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless`. Managed delivery uses
`DD_SITE`, `DD_ENV`, and `DD_API_KEY`. A custom
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL` is fetched as supplied;
it does not receive the Datadog API key. Explicit source selection takes precedence
over the legacy provider setting. Existing applications using only
`DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED` retain their Agent Remote Config source.

The sidecar subprocess owns CDN HTTP. PHP queues registration on its existing
sidecar transport without waiting for a reply. The ordinary Remote Config manager
reads the combined Agent/CDN directory and uses the existing product parsers;
there is no dedicated PHP reader thread or separate CDN IPC connection.
Accepted CDN replacements are published atomically to the process-wide evaluator.
Other Agent products retain their existing per-thread processing.

The first evaluation can wait up to
`DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS` (default 10000 ms).
Later evaluations do not renew that initialization budget. The default poll
interval is 30 seconds and request timeout is 5 seconds, controlled by
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS`.
Shutdown and fork handling use the ordinary sidecar lifecycle. If a native
framework's tracing hook is disabled, the first flag evaluation performs the
pending child reset before using the sidecar transport.

The pinned libdatadog disables CDN fetching in thread-mode listeners, including
AUTO fallback to thread mode. PHP retains its explicit unsupported-mode diagnostic;
supporting this mode requires changing and validating libdatadog as well.

PHP consumes changes through ordinary RC notifications and at flag evaluation.
The pinned libdatadog sends CDN notifications only for RC-enabled connections;
with `DD_REMOTE_CONFIG_ENABLED=0`, evaluation-time reads provide updates.
There is no independent PHP heartbeat or idle-time sidecar restart worker.
Evaluations reannounce registration at most once per second through a nonblocking
IPC send, so dead-connection detection does not depend on telemetry traffic. The
existing transport owns reconnection and replays CDN registration.

Retention uses normal Remote Config semantics. A malformed response, transport
failure, or 304 leaves the last accepted configuration usable. Identical successful
responses do not advance its version. A successful changed configuration advances
the version once, and a valid empty configuration clears the old flags. Readiness
means a configuration is available; it is not a promise that the last HTTP poll
succeeded. Reconnecting the ordinary transport marks retained flags stale until the RC
reader accepts a replacement. There is no new durable cache or freshness lease.

Configuration identity is fixed during process setup. Restart PHP to change the
credentials, endpoint, or managed environment. The new identity selects a separate
Remote Config directory and starts without the old identity's evaluator state.
A sidecar restart within the same running PHP process retains parsed last-good
flags while waiting for an accepted replacement.

Configuration delivery and telemetry delivery are separate. Exposure and
evaluation telemetry continue through the configured sidecar/Agent and OTLP paths;
a working CDN connection alone does not establish that telemetry was delivered.

The local native regression tests are in `tests/ext/ffe/agentless_*.phpt`. They
cover first-use deadlines, source selection, conditional requests, failed polls,
process isolation, shutdown, fork, and sidecar restart. The FFE Rust tests cover
atomic replacement, thread ownership, concurrent initialization, and cleanup.

If initial shared-memory publication fails temporarily, the sidecar retains the
connection's registration and retries asynchronously, starting at 100 ms and
capping the delay at five seconds. Invalid settings are rejected without retry.
Replacing or clearing registration, disconnecting, or shutting down the sidecar
cancels pending retries. Connections with the same configuration share a poller.

The CDN-scoped directory replaces Agent FFE entries while preserving other Agent
products. They continue to use their normal processing path. Expiring an Agent source
that never accepted a snapshot does not clear previously accepted CDN flags.
