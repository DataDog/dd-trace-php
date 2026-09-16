# Repo context — dd-trace-php

Read only by the orchestrator (Step 0 of `SKILL.md`), not by individual reviewers. Repo-specific; not part of the shared core.

## Related skills in this repo

Existing skills live under `.claude/skills/`. Cite them as authoritative for their area. Do not invoke them, and they must not invoke this skill:

- `check-ci` — GitLab CI / GitHub Actions watch and failure investigation
- `crash-analysis` — wild crash reports (`event.json`) for this tracer
- `release-notes` — `CHANGELOG.md` for a minor/major release

`.claude/skills/dd-apm-sdk-review` is a symlink to `.agents/skills/dd-apm-sdk-review`. No name clash.
