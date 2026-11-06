# Feature Flags debug logging (PHP)

Feature Flags diagnostics come from three places, each with its own switch and
destination:

1. **The PHP SDK layer.** `DDTrace\FeatureFlags\Client` and
   `DDTrace\OpenFeature\DataDogProvider` log the value handed back to your code.
2. **The `ddtrace` extension.** It logs settings, configuration and readiness,
   every native evaluation, exposure queueing and handoff, and request shutdown.
3. **The sidecar.** It fetches Remote Configuration from the Agent and delivers
   exposures.

To see the whole story, you need all three.

> **Raw data warning.** With debug logging on, Feature Flags logs include raw
> evaluation data: targeting keys, context attributes, flag values and default
> values. These may contain personal or sensitive information. Each PHP process
> logs one `WARN` line saying so. Turn debug logging off when you are done.

## Enable

Set these settings in the environment of the PHP process or in `php.ini`, then
restart PHP-FPM or your web server:

| Environment variable | `php.ini` name | Value | Purpose |
|---|---|---|---|
| `DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED` | `datadog.experimental_flagging_provider_enabled` | `true` | Turns on Feature Flags. |
| `DD_TRACE_DEBUG` | `datadog.trace.debug` | `true` | Debug level for the extension and the sidecar. It is also the **only** switch for the PHP SDK layer's logs. |
| `DD_TRACE_LOG_FILE` | `datadog.trace.log_file` | e.g. `/var/log/php/ddtrace.log` | Log file for the extension and the sidecar. **The sidecar has no other log destination.** |
| *(PHP setting)* | `error_log` | same path as `DD_TRACE_LOG_FILE` | Destination for the PHP SDK layer's logs. |

Example `php.ini`:

```ini
datadog.experimental_flagging_provider_enabled = 1
datadog.trace.debug = 1
datadog.trace.log_file = /var/log/php/ddtrace.log
error_log = /var/log/php/ddtrace.log
```

Make sure the PHP workers can write to the file. The sidecar process must be
able to write to it too.

Remote Configuration must stay available. The defaults work: you need
`DD_REMOTE_CONFIG_ENABLED=true` and `DD_TRACE_AGENTLESS=false`. If either
setting blocks the subscription, the extension logs
`Feature Flags: configuration subscription disabled` with the reason.

## Startup order

- **Set the switches at startup, not with `ini_set()`.** Each of these is fixed
  at a different point:
  - `DD_TRACE_LOG_FILE` is opened when the extension starts.
  - Feature Flags enablement and the Remote Configuration settings are read
    once per process.
  - The sidecar's log level and log file are fixed when the process first
    connects to the sidecar, during the first request.
- **The PHP SDK layer chooses its logger when a `Client` or `DataDogProvider`
  is constructed.** It uses the request's global DDTrace logger, which is
  created the first time anything uses it. Debug must already be on by then.
  `DataDogProvider` also decides at construction whether to register the hook
  that logs OpenFeature evaluation errors.
- **Expect this line once per process, at the first request:**
  `Feature Flags: debug logging is enabled, so feature flag logs include ...`.
  If it is missing, either Feature Flags is not enabled or the extension is
  not at debug level.

## What each layer logs, and where

| Layer | Turned on by | Written to | How the line looks |
|---|---|---|---|
| PHP SDK layer | `DD_TRACE_DEBUG` only. `DD_TRACE_LOG_LEVEL=debug` does **not** turn it on. Alternatively, pass your own `DDTrace\Log\LoggerInterface` to the `Client` or `DataDogProvider` constructor. | PHP `error_log()`, so the `error_log` setting. **Not** `DD_TRACE_LOG_FILE`. With no `error_log` set, PHP uses its default destination: stderr for the CLI and built-in server, or the web server or FPM error log. | `[ddtrace] [debug] - Feature Flags: evaluated {...}` (`Client`)<br>`... Feature Flags: provider resolved {...}` (OpenFeature)<br>`... Feature Flags: OpenFeature evaluation error {...}` |
| Extension (C) | `DD_TRACE_DEBUG=true`, or a `DD_TRACE_LOG_LEVEL` that enables `debug`. | `DD_TRACE_LOG_FILE`. If that is unset, a file-path `error_log`. Otherwise PHP's error logger. | `[ddtrace] [debug] [<pid>] Feature Flags: ...`: effective settings, request readiness, `native evaluation {...}` once per evaluation, exposure queued/suppressed/handed off/dropped, request shutdown. |
| Extension (Rust) | Same as the C layer. | Same as the C layer. | `[ddtrace] [datadog_php::ffe] [<pid>] Feature Flags: ...` and `[ddtrace] [datadog_php::remote_config] [<pid>] ...`: configuration received/applied (usable/disabled/rejected counts, environment, version)/rejected/removed. |
| Sidecar | `DD_TRACE_DEBUG=true` (debug). Otherwise `DD_TRACE_LOG_LEVEL`. | `DD_TRACE_LOG_FILE` only. Without it, sidecar logs are discarded, even when `error_log` points to a file. | `[<time>] [ddtrace] [<level>] [sidecar] ...`: Remote Configuration fetch errors (error level), `Received remote config ...` (debug), `ffe_exposures_flusher: sent exposure batch, status=...` (debug), `ffe_exposures_flusher: non-2xx response ...` (warn), `ffe_exposures_flusher: request failed: ...` (debug). |

Notes:

- **Handed off does not mean delivered.** `handed off N exposure(s) to sidecar`
  means the extension passed the exposures to the sidecar. Only the sidecar's
  `ffe_exposures_flusher` lines report the HTTP outcome of sending a batch,
  either to the Agent's EVP proxy or directly to intake. A batch is dropped,
  and not queued for a later retry, whenever the sidecar logs any of these:
  - `ffe_exposures_flusher: non-2xx response`
  - `request failed`
  - `no compatible EVP route is available`
  - `no connection transport, dropping batch`
- **Request shutdown does not wait for delivery.** The shutdown log says so.
- **Several PHP processes can share one sidecar.** It logs at the most verbose
  level any of them requests, and it writes to every log file they configure.
- **In thread mode the sidecar runs inside PHP.** This happens with
  `DD_TRACE_SIDECAR_CONNECTION_MODE=thread`, or after
  `Subprocess connection failed, falling back to thread mode`. The sidecar's
  messages then go to `DD_TRACE_LOG_FILE` and are dropped if no log file is
  open. They also lose the `[sidecar]` tag and use the extension's format with
  the Rust module path instead, such as
  `[ddtrace] [datadog_sidecar::service::evp_transport] [<pid>]`.

## Filtering

There is no single Feature Flags log category:

- The C lines use the generic `ddtrace` target, shown as `[debug]`.
- The Rust lines use their module path.
- The sidecar lines carry `[sidecar]`, except in thread mode.

Search for `Feature Flags:` and `ffe_exposures_flusher` instead. Remote
Configuration fetch errors come from the sidecar and do not have the
`Feature Flags:` prefix.

`DD_TRACE_LOG_LEVEL` accepts comma-separated `target=level` directives, such as
`error,datadog_php::ffe=debug,datadog_php::remote_config=debug`. Such a filter
can narrow the Rust configuration lines, but not the C lines. `DD_TRACE_DEBUG`
overrides `DD_TRACE_LOG_LEVEL`.

Each evaluation typically logs several lines:

- `exposure queued` or `exposure suppressed`
- `native evaluation`, from the extension
- `evaluated` (`Client`) or `provider resolved` (OpenFeature), from the PHP SDK
  layer

Expect high volume on busy applications.

## Turn it off

Unset `DD_TRACE_DEBUG`. If you changed `DD_TRACE_LOG_LEVEL`, reset it to its
default, `error`. Then restart PHP.
