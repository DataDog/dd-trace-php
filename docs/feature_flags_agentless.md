# Agentless Feature Flags configuration

Enable CDN configuration with `DD_FEATURE_FLAGS_ENABLED=true` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless`. Managed delivery uses
`DD_SITE`, `DD_ENV`, and `DD_API_KEY`. A custom
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL` is fetched as supplied;
it does not receive the Datadog API key. Explicit source selection takes precedence
over the legacy provider setting. Existing applications using only
`DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED` retain their Agent Remote Config source.

The sidecar subprocess owns CDN HTTP. PHP queues registration without waiting for
a sidecar reply, then uses the normal Remote Config reader and product parser.
The process-wide reader publishes complete replacements atomically, so evaluations
on another PHP thread do not observe a temporary empty configuration. Agent Remote
Config state remains local to the PHP thread and its service/environment target.

The first evaluation can wait up to
`DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS` (default 10000 ms).
Later evaluations do not renew that initialization budget. The default poll
interval is 30 seconds and request timeout is 5 seconds, controlled by
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS`.
Shutdown and fork preparation join the local reader without waiting for CDN HTTP.
The parent and child resume their readers after the fork; the child first clears
inherited sidecar runtime state. Thread-mode sidecars, including AUTO fallback
to a thread, do not support CDN fetching. Native framework forks use the same reader stop/resume lifecycle as
pcntl forks. Reader-owned sidecar restarts preserve PHP's resolved startup
configuration, including its log destination and level.

Retention uses normal Remote Config semantics. A malformed response, transport
failure, or 304 leaves the last accepted configuration usable. Identical successful
responses do not advance its version. A successful changed configuration advances
the version once, and a valid empty configuration clears the old flags. Readiness
means a configuration is available; it is not a promise that the last HTTP poll
succeeded. A detected sidecar disconnection marks the retained configuration stale
while the reader reconnects. There is no new durable cache or freshness lease.

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

The ordinary Agent RC reader cannot replace CDN-selected flags. Other Agent
products continue to use their normal processing path. Expiring an Agent source
that never accepted a snapshot does not clear previously accepted CDN flags.
