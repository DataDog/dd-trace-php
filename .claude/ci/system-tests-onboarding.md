# Onboarding / SSI System Tests

These tests validate Single-Step Instrumentation (SSI) and library injection on
real VMs or Docker containers. They live in a separate repository
(`datadog/system-tests`) and are orchestrated by the `one-pipeline` shared
template included via `.gitlab/one-pipeline.locked.yml`. The dd-trace-php CI
only configures which scenario groups to run.

Unlike other CI job groups, these tests **cannot be reproduced with `dockerh`**.
They require either AWS infrastructure or (with severe limitations) Vagrant.

## CI Jobs

**Source:**
- `.gitlab/generate-package.php` -- defines `configure_system_tests` (lines 114-117)
- `.gitlab/one-pipeline.locked.yml` -- shared template that expands
  `configure_system_tests` into the actual child jobs
- `system-tests` repo (`~/repos/system-tests`) -- test runner, scenarios, and
  weblog definitions

The `configure_system_tests` job in `generate-package.php` sets:

```yaml
configure_system_tests:
  variables:
    SYSTEM_TESTS_SCENARIOS_GROUPS: "simple_onboarding,simple_onboarding_profiling,simple_onboarding_appsec,lib-injection,lib-injection-profiling,docker-ssi"
    ALLOW_MULTIPLE_CHILD_LEVELS: "false"
```

The shared template expands each scenario group into jobs that:
1. Build the tracer OCI image from the pipeline's packaging artifacts
2. Spin up AWS EC2 instances (or Docker containers for `docker-ssi`)
3. Install the Datadog Agent + library injector + tracer via SSI
4. Run the weblog application and validate traces against the Datadog backend

| Scenario group | What it tests |
|---|---|
| `simple_onboarding` | Basic SSI auto-injection on various OS/arch combinations |
| `simple_onboarding_profiling` | SSI with continuous profiling enabled |
| `simple_onboarding_appsec` | SSI with AppSec enabled |
| `lib-injection` | Kubernetes lib-injection (init container injection) |
| `lib-injection-profiling` | Kubernetes lib-injection with profiling |
| `docker-ssi` | Docker-based SSI (no VM, uses Docker-in-Docker) |

Runner: `docker-in-docker:amd64` (all scenario groups)
Image: `docker:29.4.0-noble`

### CI secrets

CI fetches secrets from AWS SSM (parameter store). The relevant parameters:

| SSM parameter | Maps to env var |
|---|---|
| `ci.dd-trace-php.dd-api-key-onboarding` | `DD_API_KEY_ONBOARDING` |
| `ci.dd-trace-php.dd-app-key-onboarding` | `DD_APP_KEY_ONBOARDING` |
| `ci.dd-trace-php.onboarding-aws-infra-subnet-id` | `ONBOARDING_AWS_INFRA_SUBNET_ID` |
| `ci.dd-trace-php.onboarding-aws-infra-security-groups-id` | `ONBOARDING_AWS_INFRA_SECURITY_GROUPS_ID` |

Backend validation hits the production `system-tests` Datadog organization at
`dd.datadoghq.com`. The API/APP keys come from that org.

## What It Tests

The onboarding tests verify that the full SSI installation flow works
end-to-end: the Datadog Agent installs correctly, the injector injects the
tracer into the PHP process, and traces arrive at the Datadog backend. This
covers:

- Package installation via the Datadog installer (`install.datad0g.com` for dev,
  `install.datadoghq.com` for prod)
- Auto-injection of the PHP tracer into Apache/FPM/CLI processes
- Correct trace submission to the Agent and then to the backend
- Profiling and AppSec activation when those scenario groups are selected

## Local Reproduction: AWS (recommended)

This is the faithful reproduction path -- it uses the same infrastructure as CI.

### Prerequisites

1. **AWS access** to the `dev-apm-dcs-system-tests` account. Request access via:
   <https://datadoghq.atlassian.net/jira/software/c/projects/CLOUDA/forms/form/direct/4/26949>

2. **Pulumi >= 3.69.0** installed and logged in locally:
   ```bash
   pulumi login --local
   ```

3. **aws-vault** installed and the
   `sso-dev-apm-dcs-system-tests-account-admin` SSO profile configured
   in `~/.aws/config`. This specific account is required — it has the
   AMI mappings, IAM instance profiles, and VPC networking that the
   tests expect. Generic sandbox accounts (e.g.
   `k9-security-ecosystems-sandbox`) will fail with
   `collecting instance settings: empty result`.

   Request access via:
   <https://datadoghq.atlassian.net/jira/software/c/projects/CLOUDA/forms/form/direct/4/26949>

   Authenticate via SSO, then verify:
   ```bash
   aws sso login --profile sso-dev-apm-dcs-system-tests-account-admin
   aws-vault exec sso-dev-apm-dcs-system-tests-account-admin -- \
       aws sts get-caller-identity
   ```
   See the AWS SSO setup guide:
   <https://datadoghq.atlassian.net/wiki/spaces/ENG/pages/2498068557/AWS+SSO+Getting+Started>

4. **system-tests repo** cloned at `~/repos/system-tests` (sibling of
   `dd-trace-php`).

5. Build the runner image (installs Python deps + Pulumi providers):
   ```bash
   cd ~/repos/system-tests
   ./build.sh -i runner
   ```

### Required environment variables

```bash
export DD_API_KEY_ONBOARDING=<from system-tests Datadog org>
export DD_APP_KEY_ONBOARDING=<from system-tests Datadog org>
export ONBOARDING_AWS_INFRA_SUBNET_ID=subnet-0597477128c3d3a6b
export ONBOARDING_AWS_INFRA_SECURITY_GROUPS_ID=sg-02e547f03cf2b5955
export ONBOARDING_LOCAL_TEST=true
export SKIP_AMI_CACHE=true
export PULUMI_CONFIG_PASSPHRASE=""
```

The API/APP keys are from the system-tests Datadog organization:
<https://system-tests.datadoghq.com/dashboard/zqg-kqn-2mc>

The subnet and security group defaults above are documented in the wizard and
should work for the `dev-apm-dcs-system-tests` account.

### Running a test

```bash
cd ~/repos/system-tests

# Simple onboarding scenario, PHP 8.3 container weblog, dev env, Amazon Linux 2023
aws-vault exec sso-dev-apm-dcs-system-tests-account-admin -- \
  ./run.sh SIMPLE_INSTALLER_AUTO_INJECTION \
    --vm-weblog test-app-php-container-83 \
    --vm-env dev \
    --vm-library php \
    --vm-provider aws \
    --vm-only Amazon_Linux_2023_amd64
```

Key flags:
- `--vm-env dev` -- uses `install.datad0g.com` (dev snapshots); use `prod` for
  released versions
- `--vm-provider aws` -- provisions real EC2 instances via Pulumi
- `--vm-only <VM_NAME>` -- restricts to a single VM; without it, all VMs in the
  matrix run (slow and expensive)
- `--vm-weblog <WEBLOG>` -- selects the PHP weblog variant

To test a custom tracer build from your pipeline:
```bash
export DD_INSTALLER_LIBRARY_VERSION="pipeline-<your-pipeline-id>"
```

### Using the wizard (interactive)

The system-tests repo provides an interactive wizard that prompts for all
variables and builds the `run.sh` command:

```bash
cd ~/repos/system-tests
./build.sh -i runner
source venv/bin/activate
bash utils/scripts/ssi_wizards/aws_onboarding_wizard.sh
```

The wizard will prompt for AWS credentials, scenario, weblog, VM, and
environment, then offer to execute the final command.

### Keeping VMs alive for debugging

```bash
export ONBOARDING_KEEP_VMS=true
```

When set, VMs are not destroyed after the test. You can SSH into them to
inspect logs. Remember to destroy the Pulumi stack manually when done:

```bash
aws-vault exec sso-dev-apm-dcs-system-tests-account-admin -- pulumi destroy
```

## Local Reproduction: Vagrant (limited -- not recommended)

Vagrant replaces AWS with local VMs. Replace `--vm-provider aws` with
`--vm-provider vagrant` in the `run.sh` invocation. This avoids the need for
AWS credentials and infrastructure.

**This path has significant limitations and most tests will not pass.**

### Why Vagrant does not work well

1. **No real Amazon Linux 2023.** Vagrant maps `Amazon_Linux_2023_amd64` to
   `generic/centos9s` (CentOS 9 Stream), which is not the same OS. Package
   repositories, default packages, and system behavior differ.

2. **`podman-docker` instead of Docker.** On CentOS 9, `yum install docker`
   installs the `podman-docker` shim, not the actual Docker daemon.
   `docker-compose` cannot connect to `/var/run/docker.sock`, so container
   weblogs never start.

3. **IPv6 resolution failures under QEMU.** QEMU's user-mode networking only
   supports IPv4. Go programs (like the Datadog installer) bypass `gai.conf`
   and attempt IPv6 resolution for `install.datad0g.com`, causing connection
   failures.

4. **Fabric API breakage.** The Vagrant code path uses Fabric 1.x syntax
   (`from fabric.api import ...`), but the current `system-tests` virtualenv
   installs Fabric 3.x, which removed `fabric.api`.

5. **Bugs in VM provisioning code.** `utils/virtual_machine/virtual_machines.py`
   has issues at lines 277/285: dict key access without `.get()` and
   `self.name` vs `self.vm.name` mismatches that cause `KeyError` or
   `AttributeError`.

6. **Backend 401 errors.** Even if the VM starts, backend validation requires
   valid `DD_API_KEY_ONBOARDING` / `DD_APP_KEY_ONBOARDING` pointing at the
   system-tests Datadog org. Without these, trace validation fails with 401.

**Net result:** the container weblog never starts on most VM types, and even
with workarounds (enable podman socket, symlink, disable IPv6 at kernel level),
backend validation still fails without the API keys.

## Docker-SSI scenarios

The `docker-ssi` scenario group does not use VMs. It runs entirely in
Docker and **can be reproduced locally** without AWS access.

### Prerequisites

1. **system-tests repo** cloned (e.g. `~/repos/system-tests`).
2. **Python venv** with system-tests installed:
   ```bash
   cd ~/repos/system-tests
   uv venv venv --python 3.12
   source venv/bin/activate
   uv pip install --upgrade pip setuptools==75.8.0
   uv pip install -e .
   ```
   (Or use `./build.sh -i runner` if you have a system python3.12
   with venv/ensurepip support.)
   Both approaches are cached — re-run only after pulling new commits
   in the system-tests repo.
3. **Docker** available on the machine.
4. `DD_API_KEY_ONBOARDING` and `DD_APP_KEY_ONBOARDING` env vars.
   Docker-SSI tests validate traces against a local test agent,
   not the Datadog backend — any non-empty value works (e.g.
   `export DD_API_KEY_ONBOARDING=deadbeef`). The AWS VM scenarios
   require real keys from the system-tests Datadog org.

### Non-interactive run

Use `./run.sh` directly. The `--ssi-base-image` flag takes the
**Docker image name** (not the friendly name from the JSON matrix).
Resolve names via `utils/docker_ssi/docker_ssi_images.json`.

```bash
cd ~/repos/system-tests
source venv/bin/activate

# PHP docker-ssi on Ubuntu 22.04 amd64, prod env
DD_API_KEY_ONBOARDING=<key> DD_APP_KEY_ONBOARDING=<key> \
  ./run.sh DOCKER_SSI \
    --ssi-weblog php-app \
    --ssi-library php \
    --ssi-base-image 'public.ecr.aws/lts/ubuntu:22.04' \
    --ssi-arch linux/amd64 \
    --ssi-env prod

# With AppSec enabled
DD_API_KEY_ONBOARDING=<key> DD_APP_KEY_ONBOARDING=<key> \
  ./run.sh DOCKER_SSI_APPSEC \
    --ssi-weblog php-app \
    --ssi-library php \
    --ssi-base-image 'public.ecr.aws/lts/ubuntu:22.04' \
    --ssi-arch linux/amd64 \
    --ssi-env prod
```

To test a specific PHP runtime version (matching the CI matrix):
```bash
./run.sh DOCKER_SSI \
  --ssi-weblog php-app \
  --ssi-library php \
  --ssi-base-image 'public.ecr.aws/lts/ubuntu:22.04' \
  --ssi-arch linux/amd64 \
  --ssi-env prod \
  --ssi-installable-runtime 8.3
```

Without `--ssi-installable-runtime`, no specific PHP version is
installed — the base image's system PHP is used (PHP 8.1 on Ubuntu
22.04). In CI, every version from 5.6 to 8.3 runs as a separate
matrix cell (from `utils/docker_ssi/docker_ssi_runtimes.json`).

Available PHP scenarios: `DOCKER_SSI`, `DOCKER_SSI_APPSEC`.
`DOCKER_SSI_APPSEC` does **not** exercise AppSec attack detection or
WAF rules — it only verifies that `DD_APPSEC_ENABLED=true` is
propagated through SSI injection and reported in telemetry. If
`DOCKER_SSI` passes but `DOCKER_SSI_APPSEC` fails for the same
runtime, the issue is in how the installer handles
`DD_APPSEC_ENABLED`, not in AppSec logic.

Available PHP weblogs: `php-app`.
Available base images for PHP: `Ubuntu_22_amd64` (`public.ecr.aws/lts/ubuntu:22.04`),
`Ubuntu_22_arm64` (same image, `linux/arm64`).

Additional flags:
- `-B` / `--ssi-force-build` — force rebuild all Docker layers
  (skip local cache). Only needed when debugging install scripts or
  testing changes to the Dockerfile chain.
- `--ssi-installable-runtime <version>` — install a specific PHP
  runtime version. Available: 5.6, 7.0–7.4, 8.0–8.3.

Use `--ssi-env dev` to test development snapshots (from
`install.datad0g.com`), or `--ssi-env prod` for released versions.

To test a custom tracer build from a CI pipeline:
```bash
./run.sh DOCKER_SSI ... --ssi-library-version pipeline-<pipeline-id>
```

SSI tests (both Docker-SSI and VM-based) always install the tracer
from the OCI registry — there is no way to inject a locally built
`.tar.gz`. To test local changes via SSI, push a branch and use
`--ssi-library-version pipeline-<pipeline-id>`.

To test locally built packages without SSI (via the traditional
`datadog-setup.php` install path), see
[system-tests.md](system-tests.md) § Reproducing Locally. Those
tests exercise the same tracer/appsec code but do not test the SSI
injection mechanism itself.

### Interactive wizard

Alternatively, use the interactive wizard:

```bash
cd ~/repos/system-tests
source venv/bin/activate
bash utils/scripts/ssi_wizards/docker_ssi_wizard.sh
```

The wizard prompts for language, scenario, weblog, and base image,
then constructs and runs the `./run.sh` command.

## Gotchas

- The `one-pipeline` shared template is fetched from a remote URL locked in
  `.gitlab/one-pipeline.locked.yml`. The actual job definitions (runner tags,
  script steps, secret mappings) are not visible in the dd-trace-php repo --
  you must look at the `system-tests` repo and the shared template to
  understand what runs.

- `--vm-env dev` uses `install.datad0g.com` (development package repository),
  while `--vm-env prod` uses `install.datadoghq.com` (production). When
  testing unreleased changes, always use `dev`.

- `SKIP_AMI_CACHE=true` is required for local runs. Without it, the test
  framework tries to look up cached AMIs that only exist in CI.

- `ONBOARDING_LOCAL_TEST=true` adjusts behavior for local execution (e.g.,
  skipping CI-specific artifact paths).

- EC2 instances cost money. Always use `--vm-only` to restrict to a single VM
  when iterating. If using `ONBOARDING_KEEP_VMS=true`, destroy the stack when
  done.

- The subnet and security group values are specific to the
  `dev-apm-dcs-system-tests` AWS account. Using a different account requires
  different networking values.

- `--ssi-base-image` takes the **Docker image name** (e.g.
  `public.ecr.aws/lts/ubuntu:22.04`), not the friendly name from the
  JSON matrix (e.g. `Ubuntu_22_amd64`). Passing the friendly name
  causes `invalid reference format: repository name must be lowercase`.
  Look up the mapping in `utils/docker_ssi/docker_ssi_images.json`.

- Building system-tests venv requires `g++` (for the `brotli` C++
  extension used by `mitmproxy`). If `g++` is not available, install
  it before running `uv pip install -e .`.

- The CI matrix for Docker-SSI PHP runs **every PHP version
  (5.6–8.3) × every base image × every scenario** as separate
  parallel jobs. For `php-app` on Ubuntu 22.04, that is 10 runtime
  versions × 2 architectures × 2 scenarios = 40 jobs. Use
  `--ssi-installable-runtime` to test a single version locally.

- First local Docker-SSI run is slow (~5–7 min) due to Docker image
  builds (OS deps, PHP runtime, SSI installer). Subsequent runs
  reuse the Docker cache and take ~1–2 min. The CI ECR image cache
  (`PRIVATE_DOCKER_REGISTRY`) is not available locally.

- On Apple Silicon, Docker-SSI scenarios may need
  `DOCKER_DEFAULT_PLATFORM=linux/amd64` to match CI behavior.

- After a Docker-SSI run, logs are in `logs_docker_ssi/` (or
  `logs_docker_ssi_appsec/`) under the system-tests directory.
  The scenario name is lowercased by the framework.
