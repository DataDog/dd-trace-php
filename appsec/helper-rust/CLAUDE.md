# helper-rust - AppSec Helper Rust Rewrite

## Purpose

This project is a Rust rewrite of the Datadog AppSec helper for PHP, which provides application security monitoring and runtime protection capabilities. The helper is a library loaded into sidecar that:

- Executes the Datadog WAF (Web Application Firewall) on request data
- Handles remote configuration updates for security rules
- Collects and submits telemetry metrics
- Provides RASP (Runtime Application Self-Protection) capabilities
- Extracts API schema information
- Manages security actions (blocking, redirecting, recording events)

## Related Code

- **C++ helper (original)**: `../src/helper/`
  - Reference implementation for all features
  - Telemetry definitions: `../src/helper/telemetry.hpp`, `tags.hpp`, `metrics.hpp`
  - Remote config: `../src/helper/remote_config/`
  - WAF integration: `../src/helper/subscriber/waf.cpp`
  - Protocol: `../src/helper/network/proto.hpp`

- **PHP extension**: `../src/extension` (integration point)

- **libddwaf Rust bindings**: `../third_party/libddwaf-rust/` (path dependency)

- **libddwaf C++ library**: `../third_party/libddwaf/` (built separately for LIBDDWAF\_PREFIX)

## Architecture

### Current Architecture (Standalone Binary)

```
PHP Extension
    ↓ (Unix socket + msgpack)
helper-rust (loaded by sidecar)
    ↓ (FFI)
libddwaf
```

### Target Architecture (Sidecar Integration)

Eventually, we'll move to:

```
PHP Extension
    ↓ (sidecar protocol)
Sidecar ← helper-rust (embedded)
    ↓ (FFI)
libddwaf
```

## Key Components

- **src/main.rs** - Entry point, Unix socket server
- **src/client.rs** - Client connection handler, request processing
- **src/service.rs** - Service management, rate limiting, WAF lifecycle
- **src/rc.rs** - Remote configuration client (shared memory reader)
- **src/telemetry.rs** - Telemetry definitions (not yet implemented)
- **src/client/protocol.rs** - Msgpack protocol codec
- **src/service/updateable_waf.rs** - Thread-safe WAF wrapper with atomic updates

## Building

### Using Gradle (Integration Tests)

The helper-rust is built via Gradle for integration testing. From `tests/integration/`:

```bash
# Build helper-rust and libddwaf
./gradlew buildHelperRust --info

# The output files are in the php-helper-rust Docker volume:
# - libddappsec-helper-rust.so
# - libddwaf.so
```

The build task:
1. Builds libddwaf as a shared library using CMake
2. Builds helper-rust with Cargo, setting `LIBDDWAF_PREFIX` to point to the libddwaf installation

## Development Notes

- Uses Tokio for async runtime
- Built with Rust 2021 edition
- Unit tests should be added for new features using `#[test]`

### Integration Tests

Integration tests run via Gradle from `tests/integration/`:

```bash
./gradlew :test8.3-debug -PuseHelperRust --info \
    --tests "com.datadog.appsec.php.integration.Apache2FpmTests.test sampling priority"
```

(if omitting -PuseHelperRust, the C++ helper implementation will be used)

Logs for the helper are available at `tests/integration/build/test-logs/{helper,appsec}.log`

### Test Targets by PHP Version/Variant

Some test classes require specific PHP versions or ZTS (Zend Thread Safety) variants:

 | Test Class             | Required Target          | Condition           |
 | ------------           | -----------------        | -----------         |
 | FrankenphpClassicTests | test8.4-release-zts      | PHP 8.4 + ZTS       |
 | FrankenphpWorkerTests  | test8.4-release-zts      | PHP 8.4 + ZTS       |
 | Laravel8xTests         | test7.4-debug            | PHP 7.4, non-ZTS    |
 | Symfony62Tests         | test8.1-debug            | PHP 8.1, non-ZTS    |
 | RoadRunnerTests        | test7.4-debug (or later) | PHP >= 7.4, non-ZTS |

Available gradle targets follow the pattern: `test{version}-{variant}` where:
- version: 7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4
- variant: debug, release, release-zts

## Style

- Do not add comments describing what you're doing. Instead, if not obvious, explain the rationale behind some code.
- Put the public API at the top and move implementation types and implementation details to the bottom. As a subsidiary rule, prefer to put functions immediately after their caller, if possible.

## System Tests

System tests are located in `../../../system-tests/` (relative to dd-trace-php root) and provide end-to-end testing for the tracer and AppSec functionality.

### Setting Up Binaries for System Tests

Before running system tests with the Rust helper, copy the required binaries:

```bash
# Build helper-rust via Gradle
cd tests/integration
./gradlew buildHelperRust --info

# Extract binaries from Docker volume
docker run -i --rm -v php-helper-rust:/vol alpine cat /vol/libddappsec-helper-rust.so > ../../system-tests/binaries/libddappsec-helper.so
docker run -i --rm -v php-helper-rust:/vol alpine cat /vol/libddwaf.so > ../../system-tests/binaries/libddwaf.so

# If there were modifications in ddtrace or the extension relative to the latest origin/master:
./gradlew buildAppsec-8.0-release buildTracer-8.0-release --info

docker run -i --rm  -v php-appsec-8.0-release:/appsec alpine cat /appsec/ddappsec.so > ../system-tests/binaries/ddappsec.so
docker run -i --rm  -v php-tracer-8.0-release:/tracer alpine cat /tracer/build_extension/.libs/ddtrace.so > ../system-tests/binaries/ddtrace.so
```

### Running System Tests

From the `system-tests/` directory:

```bash
# Build the PHP weblog image
./build.sh php

# Run a specific scenario
./run.sh <SCENARIO_NAME>

# Run a scenario group
./run.sh <SCENARIO_GROUP>_SCENARIOS
# e.g., ./run.sh APPSEC_SCENARIOS
```

### Running Specific Tests

```bash
# Run a specific test file
./run.sh SCENARIO_NAME tests/path_to_test.py

# Run a specific test class
./run.sh tests/appsec/waf/test_addresses.py::Test_BodyJson

# Run a specific test method
./run.sh tests/appsec/waf/test_addresses.py::Test_BodyJson::test_body_json

# Run tests matching a pattern
./run.sh SCENARIO_NAME -k "test_pattern"
```

### PHP/AppSec Relevant Scenarios

Core AppSec scenarios:
- `APPSEC_BLOCKING` - Misc tests for AppSec blocking
- `APPSEC_CUSTOM_RULES` - Test custom AppSec rules file
- `APPSEC_MISSING_RULES` - Test missing AppSec rules file
- `APPSEC_CORRUPTED_RULES` - Test corrupted AppSec rules file
- `APPSEC_CUSTOM_OBFUSCATION` - Test custom obfuscation parameters
- `APPSEC_RATE_LIMITER` - Tests with low rate trace limit
- `APPSEC_WAF_TELEMETRY` - WAF telemetry tests

API Security scenarios:
- `APPSEC_API_SECURITY` - API Security with schema types
- `APPSEC_API_SECURITY_RC` - API Security Remote config
- `APPSEC_API_SECURITY_NO_RESPONSE_BODY` - API Security without response body
- `APPSEC_API_SECURITY_WITH_SAMPLING` - API Security with sampling

Standalone/APM opt-out scenarios:
- `APPSEC_STANDALONE` - AppSec standalone mode
- `APPSEC_STANDALONE_API_SECURITY` - API Security in standalone mode

RASP scenarios:
- `APPSEC_RASP` - RASP with internal server
- `APPSEC_RASP_NON_BLOCKING` - RASP with non-blocking rules
- `APPSEC_STANDALONE_RASP` - RASP standalone (tracing disabled)

Remote configuration scenarios:
- `APPSEC_BLOCKING_FULL_DENYLIST` - Rules from remote config
- `APPSEC_RUNTIME_ACTIVATION` - AppSec activation via remote config
- `REMOTE_CONFIG_MOCKED_BACKEND_ASM_FEATURES` - RC with ASM features
- `REMOTE_CONFIG_MOCKED_BACKEND_ASM_DD` - RC with ASM DD backend

User event scenarios:
- `APPSEC_AUTO_EVENTS_EXTENDED` - Extended automatic user events
- `APPSEC_AUTO_EVENTS_RC` - User ID collection via RC

Other scenarios:
- `GRAPHQL_APPSEC` - AppSec for GraphQL
- `APPSEC_LOW_WAF_TIMEOUT` - WAF with low timeout
- `APPSEC_RULES_MONITORING_WITH_ERRORS` - Rules with errors
- `EVERYTHING_DISABLED` - AppSec disabled tests

Scenario groups (run all scenarios in a group):
- `APPSEC_SCENARIOS` - Most AppSec scenarios
- `APPSEC_RASP_SCENARIOS` - RASP-specific tests
- `REMOTE_CONFIG_SCENARIOS` - Remote configuration tests
- `ESSENTIALS_SCENARIOS` - Essential/core tests

### Verifying Which Helper is Running

After running a test, check `logs_<scenario>/docker/weblog/logs/helper.log` to determine which helper was used:

**C++ helper** log messages:
```
[timestamp][info][pid] Sending log messages to binding, min level info
[timestamp][info][pid] Started listening on abstract socket: @/ddappsec/...
[timestamp][info][pid] starting runner on new thread
[timestamp][info][pid] Runner running
[timestamp][info][pid] DDAS-0014-00: AppSec has started
```

**Rust helper** log messages:
```
2026-01-15T19:41:20.269325053Z [INFO] AppSec helper starting
2026-01-15T19:41:20.269907428Z [INFO] Configuration: Config { socket_path: ...
2026-01-15T19:41:20.277712636Z [INFO] AppSec helper started successfully
2026-01-15T19:41:20.277760178Z [INFO] Starting server on socket: ...
2026-01-15T19:41:20.277871261Z [INFO] Listening for connections
```

Key differences:
- C++ uses `[timestamp][level][pid]` format and messages like "Runner running", "starting runner on new thread"
- Rust uses `ISO8601_TIMESTAMP [LEVEL]` format and messages like "AppSec helper starting", "Listening for connections"

## GitLab CI

The dd-trace-php repository is mirrored from GitHub to GitLab at `DataDog/apm-reliability/dd-trace-php` via gitsync. CI pipelines run on the GitLab mirror.

### Pipeline Structure

The main `.gitlab-ci.yml` uses PHP generators to create child pipelines:

1. **Parent pipeline** runs `generate-templates` job which executes PHP scripts:
   - `.gitlab/generate-appsec.php` → `appsec-gen.yml`
   - `.gitlab/generate-tracer.php` → `tracer-gen.yml`
   - `.gitlab/generate-profiler.php` → `profiler-gen.yml`
   - `.gitlab/generate-package.php` → `package-gen.yml`
   - `.gitlab/generate-shared.php` → `shared-gen.yml`

2. **Child pipelines** are triggered from the generated YAML artifacts

### Helper-Rust CI Jobs

The appsec child pipeline (generated from `generate-appsec.php`) includes these helper-rust jobs:

| Job | Description |
|-----|-------------|
| `helper-rust build and test` | Builds helper-rust and runs `cargo test` + format check |
| `helper-rust code coverage` | Runs unit tests with coverage, uploads to codecov |
| `helper-rust integration coverage` | Runs integration tests with coverage-instrumented binary |
| `appsec integration tests (helper-rust)` | Integration tests using the Rust helper (PHP 7.4, 8.1, 8.3, 8.4-zts) |

### Checking Pipeline Status

The appsec child pipeline IID can be found in the parent pipeline's downstream pipelines. Key jobs to monitor:
- Jobs with `helper-rust` in the name for Rust-specific CI
- Jobs with `(helper-rust)` suffix run integration tests with the Rust implementation

### Reading Job Logs

The GitLab MCP tools don't include a job trace/log reader. To read job logs via the API:

```bash
# Extract the token from MCP config (project ID 355 = DataDog/apm-reliability/dd-trace-php)
jq -r '.mcpServers.gitlab.env.GITLAB_PERSONAL_ACCESS_TOKEN' ~/.claude.json

# Then fetch job trace using the token
curl -s -H "PRIVATE-TOKEN: <TOKEN>" "https://gitlab.ddbuild.io/api/v4/projects/355/jobs/<JOB_ID>/trace" > /tmp/...
```

### Monitoring CI Jobs

Use the helper script to check job status:

```bash
# Check helper-rust jobs in a pipeline (use numeric pipeline ID, not IID)
./scripts/check-ci-jobs.sh <PIPELINE_ID> helper-rust
```

To monitor a pipeline until completion and get notified, spawn a background agent with this prompt:

```
Monitor GitLab pipeline <PIPELINE_ID> for helper-rust jobs. Run this loop:
1. Run: ./scripts/check-ci-jobs.sh <PIPELINE_ID> helper-rust
2. Parse the output to get RUNNING, PASSED, FAILED counts
3. If FAILED > 0, use speak_when_done MCP to say "X helper rust jobs failed" and STOP
4. If RUNNING > 0:
   - First iteration: wait 60 seconds
   - Subsequent iterations: wait 300 seconds
   - Then repeat from step 1
5. When RUNNING == 0 and FAILED == 0, use speak_when_done MCP to say "All helper rust jobs passed"
```

## Misc Notes

- Always ask for confirmation before reverting, committing, or pushing anything with git
- Do not run commands with simply tail, as that prevents checking the progress. If you're to use tail, use also tee /tmp/&lt;logfile&gt;
