<?php

/** @generate-class-entries */

namespace Datadog\Profiling;

/**
 * Instruct the profiler to collect a time sample as soon as it can. This
 * function should only be used for testing purposes, for instance to ensure
 * that a particular stack trace is correctly gathered. Since the profiler is
 * ordinarily non-deterministic, this allows the tester to ensure that a given
 * stack trace should be present in the profile.
 */
function trigger_time_sample(): void;
