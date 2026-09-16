# AGENTS.md - dd-trace-php

## Review Guidelines

**Local agent with a skill harness:** Run the [dd-apm-sdk-review](./.agents/skills/dd-apm-sdk-review/) skill on demand when asked. It is not required before every push. If any
`P0` issues are reported, you must either fix them or get explicit authorization from the human you
are working with and record the unresolved finding in the PR description (location and class of issue only — never paste secret values, tokens, credentials, or exploit details). `P1` and `P2`
findings can be dismissed by the human.

**Reviewer without a skill harness** (for example, GitHub Codex): read and follow
[`.agents/skills/dd-apm-sdk-review/review-without-harness.md`](./.agents/skills/dd-apm-sdk-review/review-without-harness.md).
Do not load `SKILL.md` or `reviewers/report-template.md`.
