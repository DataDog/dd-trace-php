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
npx --yes @mermaid-js/mermaid-cli@latest \
  -i docs/php-ffe-stack/stack-pr3909.mmd \
  -o docs/php-ffe-stack/stack-pr3909.png
npx --yes @mermaid-js/mermaid-cli@latest \
  -i docs/php-ffe-stack/system-pr3909.mmd \
  -o docs/php-ffe-stack/system-pr3909.png
```

The first `npx` invocation downloads a headless Chromium (~150 MB,
~60 s). Subsequent runs are fast.

## Architecture rule encoded in these diagrams

The system diagrams all route the EVP-exposures and OTLP-metrics paths
through the libdatadog sidecar process (`ddog_sidecar_send_ffe_exposures`
and `ddog_sidecar_send_ffe_metrics` FFIs). This honors the dd-trace-php
architectural rule that the tracer extension performs no I/O outside the
sidecar — same pattern as DogStatsD, trace stats, telemetry self-metrics,
and traces themselves. See `DataDog/dd-trace-php#3910` review thread for
the rule and `DataDog/libdatadog#2026` for the sidecar-side support.
