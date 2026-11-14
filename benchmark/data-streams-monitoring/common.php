<?php

// What the Kafka and AMQP workloads share: the message-level work, which is
// the same for both so their numbers can be read side by side, and its
// configuration.

const MESSAGE_COUNT = 1000;
const NUM_HEADERS = 5;
// Fixed message body: str_repeat('x', PAYLOAD_SIZE).
const PAYLOAD_SIZE = 1024;
const DEFAULT_COMMIT_EVERY = 100;
// How long setup waits for the broker (to start, or to create a topic), and the
// timed consume phase for all of the messages, before giving up.
const TIMEOUT_MS = 30000;

// $name as a decimal integer >= $min, or $default when unset. Empty, signs,
// whitespace and hex are errors.
function int_from_env(string $name, int $default, int $min): int
{
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }
    $value = preg_match('/\A[0-9]+\z/', $raw)
        ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]])
        : false;
    if ($value === false) {
        throw new InvalidArgumentException("$name must be an integer >= $min, got '$raw'");
    }

    return $value;
}

// COMMIT_EVERY (integer >= 1, default 100 when unset; empty is an error):
// during the timed consume phase, commit (Kafka) or ack (AMQP) synchronously
// after every COMMIT_EVERY consumed messages and always after the last one
// (so 100 gives 10 explicit commits per iteration). COMMIT_EVERY=1 is the
// per-message sync commit, kept as an on-demand diagnostic. Not set in
// bp-runner.yml, so a pipeline variable can override it.
function commit_every_from_env(): int
{
    return int_from_env('COMMIT_EVERY', DEFAULT_COMMIT_EVERY, 1);
}

// NUM_THREADS is a pipeline variable on the other languages' branches. Fail
// rather than silently run single-threaded and report it as something else.
function check_num_threads(): void
{
    $raw = getenv('NUM_THREADS');
    if ($raw !== false && $raw !== '' && $raw !== '1') {
        throw new InvalidArgumentException("NUM_THREADS=$raw is not supported: the PHP workload is single-threaded");
    }
}

function now_ms(): float
{
    return hrtime(true) / 1e6;
}
