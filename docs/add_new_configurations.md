# Add a new configuration

This doc walks through adding and documenting a new
[configuration](https://docs.datadoghq.com/tracing/trace_collection/library_config/php/)
in the PHP tracer.

## Where configurations live

Every `DD_*` configuration is one `CONFIG(...)` or `CALIAS(...)` entry in an
x-macro table. Pick the table that matches the product:

| Table | File | Product |
|---|---|---|
| `DATADOG_CONFIGURATION` | `ext/configuration.h` | Shared infrastructure: agent connection, sidecar, telemetry, remote config, crashtracking |
| `DDTRACE_CONFIGURATION` | `tracer/configuration.h` | Tracer (most keys live here), including per-integration keys from `tracer/integrations/integrations.h` |
| `DDTRACE_PROFILING_CONFIGURATION` | `profiling/configuration.h` | Profiler |
| `DDAPPSEC_CONFIGURATION` | `appsec/src/extension/configuration_table.h` | Application security |

`ext/configuration.h` combines the four tables into `DD_ALL_CONFIGURATIONS`.
The same list generates the config IDs, the INI entries, the C accessors, the
profiler's Rust `ConfigId` (`profiling/config_codegen.rs`), and
`metadata/supported-configurations.json`.

Each entry has the form:

```c
CONFIG(TYPE, DD_NAME, "default", .option = value, ...)
CALIAS(TYPE, DD_NAME, "default", CALIASES("DD_OLD_NAME"), .option = value, ...)
```

- **Type:** `BOOL`, `INT`, `DOUBLE`, `STRING`, `MAP`, `SET`, `SET_LOWERCASE`,
  `SET_OR_MAP_LOWERCASE`, or `JSON`. Wrap it as `CUSTOM(TYPE)` when the value
  goes through a custom `.parser`.
- **Default:** always a string literal, decoded the same way as a user value.
- **Options:** fields of `zai_config_entry` in
  `zend_abstract_interface/config/config.h`:
  - `.ini_change`: accepts or rejects runtime changes. Use
    `zai_config_system_ini_change` for settings that can only be set at
    startup.
  - `.parser`: custom decoding.
  - `.displayer`: custom output in `php --ri ddtrace`.
  - `.env_config_fallback`: reads an `OTEL_*` equivalent when the `DD_*` value
    is unset.
  - `.sensitive = true`: marks secrets.

The INI name is derived from the env name. `DD_` becomes `datadog.`, the rest
is lowercased, and the `trace`, `profiling`, `appsec`, and
`dynamic_instrumentation` prefixes get a dot. For example, `DD_TRACE_DEBUG`
becomes `datadog.trace.debug`, and `DD_API_KEY` becomes `datadog.api_key`.

### Source priority

`zend_abstract_interface/config/` resolves each value from these sources, from
highest to lowest priority. The `tests/ext/library_config/` tests cover the
order.

1. **Fleet stable config:** Declarative Configuration distributed through Fleet
   Automation.
2. **Environment variables:** the SAPI environment (e.g. Apache `SetEnv`)
   first, then the process environment.
3. **Local stable config:** a local Declarative Configuration `.yaml` file.
4. **OpenTelemetry fallback:** the entry's `.env_config_fallback`, if it has
   one.
5. **INI:** `datadog.*` settings in `php.ini` and `conf.d`.
6. **Default:** the value in the `CONFIG(...)` entry.

This order applies per name. ZAI checks the canonical name against sources 1–3
before it moves on to the first alias, so the canonical name set in local
stable config beats an alias set in the environment. Settings without a
`zai_config_system_ini_change` guard can also be changed per request with
`ini_set()`, and some keys are also driven by Remote Configuration.

### Reading a value

`ext/configuration_helpers.h` generates two C accessors for every entry:

- `get_DD_NAME()`: the value for the current request, including runtime
  changes.
- `get_global_DD_NAME()`: the value decoded at startup. Use it outside a
  request and for system-only settings.

The profiler reads its values through the generated `ConfigId` in
`profiling/src/config.rs`. PHP userland and tests can read any value with
`dd_trace_env_config("DD_NAME")`. Read values through these accessors; don't
call `getenv()` or `ini_get()` for a `DD_*` key.

## Adding a configuration

1. **Check whether the configuration already exists** in another tracer in the
   [Feature Parity Dashboard](https://feature-parity.us1.prod.dog/#/configurations?viewType=configurations).
   Search by env var name or description. If it exists, reuse its name and
   semantics.
2. **Add the entry** to the right table above. Keep the `DD_` prefix in the
   name. Use `CALIAS` and `CALIASES(...)` for older names you need to keep;
   an entry can have at most three aliases (`ZAI_CONFIG_NAMES_COUNT_MAX`).
   The total number of entries is capped by `ZAI_CONFIG_ENTRIES_COUNT_MAX`
   in `zend_abstract_interface/config/config.h`. A static assertion in
   `ext/configuration.c` fails the build when you go over it; raise the cap
   then.
3. **Mark secrets** with `.sensitive = true`. Sensitive values are left out of
   configuration telemetry and flagged as sensitive in the metadata.
4. **Read the value** through the accessors above.
5. **Regenerate the metadata:**

   ```bash
   bash tooling/generate-supported-configurations.sh
   ```

   This rewrites `metadata/supported-configurations.json` (and `ext/version.h`)
   from the config tables. It needs `php`, `php-config`, and a C compiler.
   Commit the JSON with your change. The `Configuration Consistency` CI job
   reruns the script and fails if the committed file differs; see
   [its local repro](../.claude/ci/shared-zai-tea-tests.md#configuration-consistency)
   if you don't have the toolchain. Don't edit the JSON by hand, except for
   the `deprecations` and `implementation` values described below, which the
   script keeps.
6. **Register the configuration** in the Feature Parity Dashboard if no other
   tracer has it, or if PHP's behavior differs from the existing
   implementation. The `validate_supported_configurations_v2_local_file` CI
   job checks the JSON against the central registry, so an unregistered name,
   alias, or implementation fails there.

### What the generator writes

Each key in `supportedConfigurations` is the env var name. Its value is a list
of implementation entries:

- `implementation`: the implementation label from the Feature Parity
  Dashboard. New keys start as `A`. For existing keys, the script never
  changes the label, so set it by hand when the registry assigns a different
  one.
- `type`: `boolean`, `int`, `decimal`, `string`, `map`, or `array`.
  `CUSTOM(INT)` maps to `string`, because most custom integer parsers accept
  text enum values. The exceptions are the parsers listed in `map_type()` in
  the script, which validate a real integer. Add your parser there if it does
  the same.
- `default`: the default string. Booleans are normalized to `"true"` or
  `"false"`. The literal `NULL` becomes `null`.
- `aliases`: the `CALIASES(...)` names, sorted. Omitted when there are
  none.
- `sensitive`: `true` when the entry sets `.sensitive`. Omitted otherwise.

The script adds `OTEL_*` configurations from `ext/otel_config.c`,
`tracer/tracer_otel_config.c`, and the whitelist in
`src/DDTrace/OpenTelemetry/Configuration.php`. Their types and defaults come
from the `$otelMetadata` table in the script, which mirrors the OpenTelemetry
PHP SDK. Update that table when you add an `OTEL_*` configuration.

Example output:

```json
{
  "version": "2",
  "supportedConfigurations": {
    "DD_API_KEY": [
      {
        "implementation": "C",
        "type": "string",
        "default": "",
        "sensitive": true
      }
    ],
    "DD_PROFILING_ALLOCATION_ENABLED": [
      {
        "implementation": "B",
        "type": "boolean",
        "default": "true",
        "aliases": [
          "DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED"
        ]
      }
    ]
  },
  "deprecations": {}
}
```

To deprecate a name, keep it working as an alias of the new name and add it to
`deprecations` with a short note, e.g.
`"DD_OLD_NAME": "use DD_NEW_NAME instead"`.

For more on the central registry, see
[APM - Centralized Configuration](https://datadoghq.atlassian.net/wiki/spaces/APMINT/pages/5372248222/APM+-+Centralized+Configuration+Config+inversion).

## Verifying the configuration

Add a `.phpt` test under `tests/ext/` that sets the value through `--ENV--`
and `--INI--` and checks the result with `dd_trace_env_config()` or the
behavior it controls. To test priority against stable config, copy one of the
tests in `tests/ext/library_config/`. Profiler and AppSec settings have their
own suites under `profiling/tests/` and `appsec/tests/`.
