# Agent instructions

Project knowledge and operating rules live in [.claude/](.claude/): start
with [.claude/general.md](.claude/general.md) and
[.claude/project/index.md](.claude/project/index.md).

## CI scripts

Every `curl` that runs in CI (GitLab jobs and their `.gitlab/generate-*.php`
templates, GitHub Actions, the scripts they call, and test harnesses that
download tools) must:

- **Fail on HTTP errors**: pass `-f`/`--fail`. Without it, a 4xx/5xx exits 0
  and the error page is used as if it were the real response.
- **Retry transient errors**: pass `--retry 5 --retry-delay 2`. Add
  `--retry-connrefused` only where curl is 7.52+ (not CentOS 7, whose curl
  7.29 rejects it, e.g. in `dockerfiles/verify_packages/`).
- **Propagate the failure**: the job must stop when curl fails (`set -e`,
  `|| exit 1`, or `set -o pipefail` for `curl | tar`/`curl | jq`). Never
  replace a failed API call with a default such as `|| echo "[]"`; that
  turns an outage into a silent pass.

Example: `curl -fsSL --retry 5 --retry-delay 2 -o out.tar.gz "$url"`

Exceptions must be explicit and commented: a script that checks the HTTP
status itself (`-w "%{http_code}"`) or a polling loop that is its own retry
(e.g. `.gitlab/wait-for-service-ready.sh`) may skip `-f`/`--retry`, and a
deliberately best-effort download must handle the failure visibly.
