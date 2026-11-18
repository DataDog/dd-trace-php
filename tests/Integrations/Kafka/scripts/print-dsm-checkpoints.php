<?php

// Prints each Data Streams Monitoring checkpoint the Kafka integration sets: its edge tags, sorted
// as they are hashed, and the pathway hash it hands on.
\DDTrace\install_hook('DDTrace\Internal\dsm_set_checkpoint', null, static function (\DDTrace\HookData $hook) {
    $tags = $hook->args[0];
    sort($tags);
    $context = $hook->returned === null ? '' : base64_decode($hook->returned);
    echo sprintf(
        "checkpoint: %s -> %s\n",
        implode(',', $tags),
        strlen($context) >= 8 ? sprintf('%u', unpack('P', substr($context, 0, 8))[1]) : '-'
    );
});
