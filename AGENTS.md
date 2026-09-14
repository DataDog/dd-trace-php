# AGENTS.md - dd-trace-php

## Review Guidelines

Before pushing or opening a PR, run the [dd-apm-sdk-review](./.agents/skills/dd-apm-sdk-review/SKILL.md)
skill on your changes. Fix blocking findings first, or get explicit authorization from the human you
work with and record them verbatim in the PR description. Never post security findings in a PR
description; route them through [SECURITY.md](SECURITY.md).

**Reviewer without a skill harness** (for example, GitHub Codex): read and follow
`.agents/skills/dd-apm-sdk-review/review-without-harness.md`. Do not load `SKILL.md`
or `reviewers/report-template.md`.
