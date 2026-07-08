# Personal Claude config

This folder is **git-ignored** (except this README). Put anything personal
here — it never gets committed and won't collide with the shared team config.

## What goes here

- `personal/CLAUDE.md` — your personal memory/instructions. The committed
  root `CLAUDE.md` imports it automatically (`@.claude/personal/CLAUDE.md`)
  if it exists, so it's loaded on top of the shared config.
- Anything else personal: notes, plans, analyses, scratch docs.

## What lives elsewhere (also git-ignored, see `.claude/.gitignore`)

- Personal slash-commands must stay in `.claude/commands/` to be discovered.
- Personal helper scripts stay in `.claude/scripts/`.
- Personal skills (e.g. `.claude/skills/omc-reference/`) stay under
  `.claude/skills/`.

## Shared config (committed)

`CLAUDE.md`, `.claude/general.md`, `.claude/project.md`, `.claude/ci/**`,
`.claude/skills/{check-ci,crash-analysis,release-notes}`, and the debugging
docs are the team's shared config — edit those in a PR, not here.
