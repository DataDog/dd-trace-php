# Benchmarks

GitLab CI configuration for the benchmarks that run on the
[Benchmarking Platform](https://datadoghq.atlassian.net/wiki/spaces/APMINT/pages/2419261562/Benchmarking+Platform).

## Layout

All benchmark jobs are defined in `benchmarks.yml`.

- `benchmarks-tracer`, `benchmarks-profiler`, `benchmarks-appsec`: microbenchmarks, extend
  `.microbenchmarks`.
    - Run on a dedicated bare-metal runner, then convert, upload and comment on the PR.
    - Steps live in the `dd-trace-php` branch of
      [benchmarking-platform](https://github.com/DataDog/benchmarking-platform).
    - `check-big-regressions` gates `benchmarks-tracer` on regressions above the threshold
      defined on `bp-runner.fail-on-regression.yml`.
- `macrobenchmarks`: k6 load test against a sample Laravel app, extends `.macrobenchmarks`.
    - Runs on `master`, manual elsewhere.
    - Steps live in the `php/laravel-realworld` branch of
      [benchmarking-platform](https://github.com/DataDog/benchmarking-platform).
    - `check-slo-breaches` gates releases based on SLOs defined on `bp-runner.fail-on-breach.yml`.
- `linux-php-laravel-realworld-parallel`, `linux-php-symfony-realworld-parallel`,
  `linux-php-wordpress-parallel`: included from
  [apm-sdks-benchmarks](https://gitlab.ddbuild.io/DataDog/apm-reliability/apm-sdks-benchmarks).
    - Change them there.

## Marking a benchmark as flaky

Add it to `FLAKY_BENCHMARKS_REGEX` in the suite's job or template:

- Microbenchmarks (`benchmarks-tracer`, `benchmarks-profiler`, `benchmarks-appsec`):
  `variables` in `.microbenchmarks` in `benchmarks.yml`.
- Macrobenchmarks (`macrobenchmarks`): `variables` in `.macrobenchmarks` in `benchmarks.yml`.

The benchmark still runs and reports, but doesn't fail the gate.

- The regex matches anywhere in the scenario name.
    - `SpanBench` quarantines every `SpanBench` scenario.
    - Anchor with `^...$` to target one scenario.

```yaml
FLAKY_BENCHMARKS_REGEX: "SpanBench|^webserver--laravel-realworld--baseline--none--normal_operation$"
```

Open a ticket to fix or remove it. See
[Flaky Benchmarks Monitoring](https://datadoghq.atlassian.net/wiki/spaces/APMINT/pages/7223313012/Flaky+Benchmarks+Monitoring).
