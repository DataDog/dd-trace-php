# Exposures in your own data store (OpenFeature)

The Datadog OpenFeature provider sends exposures to Datadog. To also record
exposures in your own data store, register `DDTrace\OpenFeature\ExposureHook`.
It receives each completed evaluation with its evaluation-time sidecar admission decision.

```php
use DDTrace\OpenFeature\ExposureEvaluation;
use DDTrace\OpenFeature\ExposureHook;
use OpenFeature\OpenFeatureAPI;

OpenFeatureAPI::getInstance()->addHooks(new ExposureHook(
    function (ExposureEvaluation $evaluation) use ($buffer): void {
        if (!$evaluation->shouldSend()) {
            return;
        }
        $features = $evaluation->getFeatures();
        $buffer->add([
            'flag' => $evaluation->getFlagKey(),
            'variant' => $evaluation->getVariant(),
            'subject' => $evaluation->getTargetingKey(),
            'holdout_key' => $features['holdout.key'] ?? null,
            'holdout_group' => $features['holdout.assignment_group'] ?? null,
        ]);
    }
));
// Write the buffer to the data store after the response is sent.
```

- The callback runs on the evaluating thread. Buffer your data and perform I/O after the response.
- `shouldSend()` is true for an `admitted` observation. Use `isExposure()` to collect every eligible observation.
- `isExposure()` is true for every successful evaluation of an allocation that
  logs exposures, including repeats. `isCacheHit()` tells repeats apart.
- An evaluation that fails, including one that a later `after` hook fails, is
  not an exposure and records nothing.
- Exposures are decided in the provider's own hook, so set the Datadog provider
  directly on OpenFeature and evaluate through an OpenFeature client. Calling
  the provider's `resolve*` methods directly, or using it inside an OpenFeature
  `MultiProvider` (which does not run the hooks of its providers), records no
  exposure and sends none to Datadog; the provider logs one warning.
  `ExposureHook` skips evaluations by any other provider, including a
  `MultiProvider`, and logs one warning.
- An exception from the callback does not affect the evaluation. The first one
  is logged as a warning, later ones at debug level.

Workers connected to the same live sidecar share decisions for a given SDK identity, EVP
route, service, environment, application version, flag and subject. This includes forked
children that connect to the parent's sidecar. The sidecar compares allocation, variant and
split serial ID with the latest resident assignment. For two workers observing A, B, A, A
in that order, the statuses are `admitted`, `admitted`, `admitted`, `repeat`.
Eviction and sidecar restart allow a fresh admission. Each delivery or hook-only domain
has a capacity of 65,536 keys.

| `getDecisionStatus()` | Meaning |
|---|---|
| `admitted` | The sidecar recorded this assignment. With delivery enabled it also queued the exposure for Datadog. |
| `repeat` | The current assignment matches the remembered one. `isCacheHit()` returns true. |
| `unavailable` | The evaluation completed without a synchronous admission decision. Eligible delivery rows can still reach Datadog through the deferred batch. |
| `unknown` | The sidecar may already have applied the observation. The tracer leaves this attempt terminal. |
| `not_exposure` | The evaluation failed or selected an allocation with exposure logging disabled. |

The corresponding `ExposureEvaluation::DECISION_*` constants are stable callback values.
Admission confirms local sidecar state; HTTP delivery and intake processing happen later.

## Features

`getFeatures()` returns the features of the selected split whose destinations
include `HOOK`. Values are a string, float or bool. Holdout splits carry:

| Feature | Value |
|---|---|
| `holdout.key` | The holdout key |
| `holdout.assignment_group` | `status_quo` or `all_shipped` |
| `holdout.should_include_in_holdout_analysis` | Whether the subject counts in the holdout analysis |

The array is empty when the split has no such features. The provider ignores
a destination it does not know and logs a warning once for each one.

## Flag metadata

With `open-feature/sdk` 2.4.0 or later, the provider also writes each `HOOK`
feature into the flag metadata as `__dd_feature.<key>`. That key is the public
contract. Other `__dd_` keys are internal and can change.

The native `DDTrace\FeatureFlags\Client` writes the same `__dd_feature.<key>`
entries into `EvaluationDetails::getFlagMetadata()`.

## Stop sending exposures to Datadog

```shell
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
```

This selects the hook-only decision domain. Eligible callbacks share hook-only assignments
across workers. Re-enabling delivery selects the delivery domain and preserves the first eligible
Datadog exposure for each new or changed assignment in that domain. The default is `true`. Configure evaluation counts with
`DD_FLAGGING_EVALUATION_COUNTS_ENABLED`.

`DD_FEATURE_FLAGS_EXPOSURES_ADMISSION_TIMEOUT_US` controls the local IPC wait (default 10,000
microseconds; range 0–50,000). Zero selects batch-only delivery: an eligible hook reports
`unavailable`, and a callback using `shouldSend()` skips that observation. Larger valid rows
(over 65,536 text bytes) can use the deferred delivery lane with their original attributes and
features. A request in overflow can buffer up to 1,000 rows within an **8 MiB exposure-data
budget per active request**. In a PHP 8.3 NTS measurement with 70 KiB of attributes per
observation, PHP worker RSS rose by about 9 MiB (8,972–8,976 KiB) by observation 128 and
stayed flat through observation 2,048. This measures the PHP worker; the sidecar uses
separate memory. Exposure outcome telemetry records deferred, confirmed, uncertain and
dropped observations.
