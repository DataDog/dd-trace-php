# Agentless Feature Flags configuration

## Settings

Feature flags are disabled by default. The source is resolved once at process
startup, in this order of precedence:

1. Explicit `DD_FEATURE_FLAGS_ENABLED=false` disables feature flags.
2. A non-blank `DD_FEATURE_FLAGS_CONFIGURATION_SOURCE` selects `agentless`,
   `remote_config` or `offline`. Any other value disables feature flags and is
   reported as an invalid source.
3. Otherwise, `DD_FEATURE_FLAGS_ENABLED=true` leaves configuration delivery
   disabled: an explicit source is required, even if the deprecated switch is set.
4. Otherwise, the deprecated `DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED`
   selects Agent Remote Configuration, or disables feature flags.
5. Otherwise, feature flags remain disabled.

`DD_FEATURE_FLAGS_ENABLED` is the canonical enable/disable switch, not a source
selector. To enable CDN fetching, set
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless`. An explicit source is sufficient
unless `DD_FEATURE_FLAGS_ENABLED=false` is also set. An absent, empty or
whitespace-only source starts no delivery, except for the legacy-only RC opt-in.

To migrate an existing RC installation away from
`DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true`, set both
`DD_FEATURE_FLAGS_ENABLED=true` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=remote_config`. Replacing only the legacy
switch with the canonical switch stops configuration delivery.

Creating or registering the OpenFeature provider does not enable fetching.
After explicit opt-in, the sidecar can fetch before provider registration.

The managed CDN endpoint is derived from `DD_SITE` and `DD_ENV`, and
authenticated with `DD_API_KEY`. A custom
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL` is fetched as given
and never receives the API key. The poll interval defaults to 30 seconds and
the request timeout to 5 seconds. They are set with
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS` and
`DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS`.
Invalid values fall back to their defaults.

Settings problems are logged once, on the first flag evaluation, so
applications that never evaluate flags get no feature flag diagnostics.
`DDTrace\Internal\ffe_provider_state()` reports the failure reason to the SDK.

## Delivery

The sidecar fetches the CDN. PHP never opens an HTTP connection for it.

- The CDN configuration is registered on the existing sidecar transport, as
  part of the connection state. No reply is awaited. The transport replays the
  registration after a reconnect and when a forked child adopts the
  connection.
- The sidecar publishes the accepted flags in a Remote Config shared-memory
  directory of their own. That directory also carries every other product of
  the Agent, but replaces the Agent's `FFE_FLAGS`.
- Each PHP thread's ordinary Remote Config reader reads that directory instead
  of the Agent's directory. It applies the flags with the same parser and
  thread-local evaluator state as Agent Remote Configuration.
- Updates arrive through Remote Config notifications, and on every evaluation.
  Notifications require `DD_REMOTE_CONFIG_ENABLED`.

The in-process thread listener does not fetch the CDN. In thread mode,
including the AUTO fallback to it, the agentless source is reported as
`unsupported_sidecar_mode`. The reader then keeps using the Agent's directory.

## Retention

- The last accepted configuration stays in use through failed polls, 304
  responses, sidecar restarts and target changes.
- A valid empty configuration clears the flags.
- Credentials, endpoint and environment are fixed at process startup. Restart
  PHP to change them.

## First evaluation

The first evaluation of the process waits until a configuration is available,
for up to `DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS`
(default 10000 ms). Later evaluations, on any thread, do not get a new wait.
A forked child that did not inherit a configuration gets its own wait.

## Telemetry

Configuration delivery and telemetry delivery are independent. Exposure and
evaluation telemetry still go through the sidecar to the Agent, or through
OTLP.

## Tests

- `tests/ext/ffe/agentless_*.phpt` cover:
  - explicit opt-in and no CDN traffic without opt-in, including provider use;
  - source selection and settings diagnostics;
  - the first-use deadline, failed polls and recovery;
  - fork, threads, Swoole workers and sidecar restarts;
  - process isolation of the CDN requests.
- The sidecar side is tested in libdatadog.
