# AGENTS.md - dd-trace-php

## Review Guidelines

**Local agent with a skill harness:** Before pushing code or creating a pull request, you must run
the [dd-apm-sdk-review](./.agents/skills/dd-apm-sdk-review/) skill on your unpushed changes. If any
`P0` issues are reported, you must either fix them or get explicit authorization from the human you
are working with and record the unresolved finding verbatim in the PR description. `P1` and `P2`
findings should be fixed before pushing, but can be dismissed by the human.

Exception: security findings are never pasted into a PR description — a PR is a public forum, so
posting one there is an improper disclosure. Route them privately
(see [SECURITY.md](SECURITY.md)).

**Reviewer without a skill harness** (for example, GitHub Codex): read and follow
[`.agents/skills/dd-apm-sdk-review/review-without-harness.md`](./.agents/skills/dd-apm-sdk-review/review-without-harness.md).
Do not load `SKILL.md` or `reviewers/report-template.md`.
