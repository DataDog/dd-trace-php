<?php

// The file:// recorder appends newline-terminated telemetry JSON alongside
// binary trace payloads. Ignore trace fragments and incomplete trailing records;
// reread the latter on the next poll instead of decoding/dereferencing null.
// Batch events inherit envelope metadata (application, runtime_id, etc.) so
// callers can filter them just like standalone events.
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
        if (!is_array($json)) {
            continue; // A binary trace can contain "\n{" bytes too.
        }
        if (!isset($json['request_type'])) {
            throw new RuntimeException('Invalid completed telemetry record: ' . $line);
        }
        $batch = $json['request_type'] === 'message-batch' ? ($json['payload'] ?? null) : [$json];
        if (!is_array($batch)) {
            throw new RuntimeException('Invalid telemetry batch: ' . $line);
        }
        $metadata = $json;
        unset($metadata['request_type'], $metadata['payload']);
        foreach ($batch as $message) {
            if (!is_array($message) || !isset($message['request_type'])) {
                throw new RuntimeException('Invalid telemetry message: ' . $line);
            }
            $messages[] = $message + $metadata;
        }
    }
    return $messages;
}
