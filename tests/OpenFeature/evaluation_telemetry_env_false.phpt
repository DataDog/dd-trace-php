--TEST--
OpenFeature disabled environment opt-out preserves explicit true and construction-time configuration
--ENV--
DD_FEATURE_FLAGS_EVALUATION_TELEMETRY_PER_SUBJECT_OPT_OUT_ENABLED=false
--INI--
datadog.metrics_otel_enabled=1
datadog.experimental_flagging_provider_span_enrichment_enabled=1
--FILE--
<?php require __DIR__ . '/evaluation_telemetry_env.inc'; ?>
--EXPECT--
omitted={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":1,"exposures":1,"annotation":true}
explicit-false={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":1,"exposures":1,"annotation":true}
explicit-true={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":0,"exposures":1,"annotation":false}
string-true={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":1,"exposures":1,"annotation":true}
missing={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":1,"exposures":1,"annotation":true}
existing-provider={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":1,"exposures":1,"annotation":true}
new-provider={"value":"blue","variant":"treatment","reason":"STATIC","error":null,"metrics":0,"exposures":1,"annotation":false}
