# Exposures in your own data store (OpenFeature)

The Datadog OpenFeature provider sends exposures to Datadog. To also record
exposures in your own data store, register `DDTrace\OpenFeature\ExposureHook`.
It receives every completed evaluation with the same exposure decision that
the provider uses for Datadog.

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

- The callback runs during the evaluation. Do not do I/O in it: copy what you
  need and write it later, for example after the response is sent.
- `shouldSend()` is true for a successful evaluation of an allocation that logs
  exposures, for a subject not yet exposed to the same allocation, variant and
  split serial id in this PHP process. These are the exposures that the
  provider sends to Datadog when Datadog logging is on.
- `isExposure()` is true for every successful evaluation of an allocation that
  logs exposures, including repeats. `isCacheHit()` tells repeats apart.
- An evaluation that fails, including one that a later `after` hook fails, is
  not an exposure and records nothing.
- Exposures are decided in the provider's own hook, so evaluate through an
  OpenFeature client. Calling the provider's `resolve*` methods directly records
  no exposure.
- An exception from the callback does not affect the evaluation. The first one
  is logged as a warning, later ones at debug level.

The exposure cache holds 65,536 entries per PHP process. The Datadog sidecar
deduplicates again across processes before it sends exposures to Datadog.

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

The tracer then sends no exposures to Datadog. `ExposureHook` callbacks still
receive every evaluation, and `shouldSend()` gives the same result as before.
The default is `true`. This setting stops exposures only; evaluation counts
have their own setting, `DD_FLAGGING_EVALUATION_COUNTS_ENABLED`.
