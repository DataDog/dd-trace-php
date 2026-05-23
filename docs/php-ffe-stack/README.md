# PHP FFE PR-stack diagrams

This directory holds the per-PR Mermaid diagrams attached to the dd-trace-php
PHP Feature Flag Evaluation (FFE) PR stack:

| PR | Layer | Diagrams |
| --- | --- | --- |
| #3906 | M1 — Evaluations (libdatadog runtime + RC + PHP 7/8 APIs) | n/a (predates this docs flow) |
| #3909 | Hook layer (this PR) | `stack-pr3909.mmd` + `system-pr3909.mmd` |
| #3910 | EVP exposures (sibling of metrics under #3909) | `stack-pr3910.mmd` + `system-pr3910.mmd` |
| #3911 | OTLP evaluation metrics (sibling of EVP under #3909) | `stack-pr3911.mmd` + `system-pr3911.mmd` |

Each PR carries two diagrams:

- **`stack-prN.mmd`** — the 4-PR stack with the current PR badged. Shows
  where this PR sits relative to siblings and ancestors.
- **`system-prN.mmd`** — the target end-to-end architecture (PHP → Hook
  → Writers → Sidecar FFI → libdatadog sidecar → Agent/OTLP intake)
  with the current PR's scope highlighted and "(future)" nodes dashed.

## Regenerating the PNGs

The `.mmd` files are the source of truth. PNGs are committed for PR
review and rendered on GitHub. To regenerate after editing:

```sh
cd /path/to/dd-trace-php
for kind in stack system; do
  for pr in 3909 3911; do
    npx --yes @mermaid-js/mermaid-cli@latest \
      -i "docs/php-ffe-stack/${kind}-pr${pr}.mmd" \
      -o "docs/php-ffe-stack/${kind}-pr${pr}.png" \
      -w 2400 -H 2400 --scale 3 -b white
  done
done
```

`-w 2400 -H 2400 --scale 3 -b white` yields crisp, white-background
PNGs (~1800×2000 for stack, ~3000×4500 for system) that read well on
PR pages and survive zooming. The first `npx` invocation downloads
a headless Chromium (~150 MB, ~60 s); subsequent runs are fast.

All diagrams use `flowchart TD` (top-to-bottom). For tall system
diagrams that keeps the PHP-process → host-sidecar → backend lanes
stacked vertically, which is easier to follow than the previous
left-to-right rendering.

`title:` values **must be quoted** in the YAML frontmatter because they
contain `#` (the PR number). Unquoted, Mermaid's YAML parser truncates
the title at the first `#`, leaving the rendered diagram with a header
like "PHP FFE 4-PR stack — current =" and no way to tell which PR it
belongs to.

## Architecture rule encoded in these diagrams

The system diagrams all route the EVP-exposures and OTLP-metrics paths
through the libdatadog sidecar process (`ddog_sidecar_send_ffe_exposures`
and `ddog_sidecar_send_ffe_metrics` FFIs). This honors the dd-trace-php
architectural rule that the tracer extension performs no I/O outside the
sidecar — same pattern as DogStatsD, trace stats, telemetry self-metrics,
and traces themselves. See `DataDog/dd-trace-php#3910` review thread for
the rule and `DataDog/libdatadog#2026` for the sidecar-side support.
