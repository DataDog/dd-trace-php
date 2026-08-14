<?php

final class OtelThreadContext
{
    private FFI $ffi;
    private FFI\CData $slot;

    public function __construct()
    {
        $this->ffi = FFI::cdef(
            <<<'C'
            void *dlopen(const char *filename, int flags);
            void *dlsym(void *handle, const char *symbol);
            typedef struct {
                uint8_t trace_id[16];
                uint8_t span_id[8];
                uint8_t valid;
                uint8_t trace_flags;
                uint16_t attrs_data_size;
                uint8_t attrs_data[612];
            } otel_thread_context_record;
            C,
        );

        $handle = $this->ffi->dlopen(null, 2);
        $symbol = $this->ffi->dlsym($handle, 'otel_thread_ctx_v1');
        if (FFI::isNull($symbol)) {
            throw new RuntimeException('otel_thread_ctx_v1 is not exported');
        }
        $this->slot = $this->ffi->cast('void **', $symbol);
    }

    public function isActive(): bool
    {
        return $this->record() !== null;
    }

    public function traceId(): ?string
    {
        $record = $this->record();
        return $record === null ? null : bin2hex(FFI::string($record->trace_id, 16));
    }

    public function spanId(): ?string
    {
        $record = $this->record();
        return $record === null ? null : bin2hex(FFI::string($record->span_id, 8));
    }

    public function traceFlags(): ?int
    {
        $record = $this->record();
        return $record === null ? null : $record->trace_flags;
    }

    public function attributes(): array
    {
        $record = $this->record();
        if ($record === null) {
            return [];
        }

        $attributes = [];
        $offset = 0;
        while ($offset + 2 <= $record->attrs_data_size) {
            $key = $record->attrs_data[$offset++];
            $size = $record->attrs_data[$offset++];
            if ($offset + $size > $record->attrs_data_size) {
                break;
            }

            $value = '';
            for ($index = 0; $index < $size; ++$index) {
                $value .= chr($record->attrs_data[$offset + $index]);
            }
            $attributes[$key] = $value;
            $offset += $size;
        }

        return $attributes;
    }

    private function record(): ?FFI\CData
    {
        if (FFI::isNull($this->slot[0])) {
            return null;
        }

        $record = $this->ffi->cast('otel_thread_context_record *', $this->slot[0]);
        return $record->valid === 1 ? $record : null;
    }
}
