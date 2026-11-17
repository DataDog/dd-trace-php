<?php

// The file:// recorder appends newline-terminated JSON. file() can return a
// trailing partial record while the sidecar is still writing it; reread that
// record on the next poll instead of decoding and dereferencing null.
function read_telemetry_file($path) {
    if (!file_exists($path)) {
        return [];
    }

    $messages = [];
    foreach (file($path) as $line) {
        if ($line === '' || $line[0] !== '{' || substr($line, -1) !== "\n") {
            continue;
        }
        $json = json_decode($line, true);
        if (!is_array($json) || !isset($json['request_type'])) {
            throw new RuntimeException('Invalid completed telemetry record: ' . $line);
        }
        $batch = $json['request_type'] === 'message-batch' ? ($json['payload'] ?? null) : [$json];
        if (!is_array($batch)) {
            throw new RuntimeException('Invalid telemetry batch: ' . $line);
        }
        foreach ($batch as $message) {
            if (!is_array($message) || !isset($message['request_type'])) {
                throw new RuntimeException('Invalid telemetry message: ' . $line);
            }
            $messages[] = $message;
        }
    }
    return $messages;
}
