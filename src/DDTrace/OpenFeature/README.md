# Exposures in your own data store (OpenFeature)

To also record exposures in your own data store, register
`DDTrace\OpenFeature\ExposureHook` on your OpenFeature client or API.
For delivery exclusively to your own store, set
`DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false`.

```php
use DDTrace\OpenFeature\ExposureEvaluation;
use DDTrace\OpenFeature\ExposureHook;
use OpenFeature\OpenFeatureAPI;

OpenFeatureAPI::getInstance()->addHooks(new ExposureHook(
    function (ExposureEvaluation $evaluation) use ($buffer): void {
        if (!$evaluation->isExposure()) {
            return;
        }
        $features = $evaluation->getFeatures();
        $buffer->add([
            'flag' => $evaluation->getFlagKey(),
            'allocation' => $evaluation->getAllocationKey(),
            'split_serial_id' => $evaluation->getSplitSerialId(),
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
- `isExposure()` is true for every successful evaluation of an allocation that
  logs exposures, including repeats.
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

Your consumer owns delivery and deduplication. To keep assignment changes, compare
`(allocation, variant, split_serial_id)` with the last assignment for each `(flag, subject)`
in your event stream. For example, `A → B → A → A` records `A, B, A`.

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

With the default `true`, workers buffer assignment changes for the sidecar,
which deduplicates across connected workers before sending to Datadog. Each
pending batch keeps up to 1,000 rows and coalesces repeats per flag and subject.
Delivery is best effort: a full buffer drops additional changes, and the transport
drops batches above its encoded-size limit (4 MiB by default).

The hook receives every completed evaluation with either setting; `isExposure()`
selects eligible observations, including repeats. Evaluation counts use their
own setting, `DD_FLAGGING_EVALUATION_COUNTS_ENABLED`.
