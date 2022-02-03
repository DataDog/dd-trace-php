#include "queue.h"

bool datadog_php_queue_try_push(datadog_php_queue *queue, void *item) {
    if (queue->size == queue->capacity) {
        return false;
    }
    ++queue->size;
    queue->buffer[queue->head] = item;
    queue->head = (queue->head + 1) % queue->capacity;
    return true;
}

bool datadog_php_queue_try_pop(datadog_php_queue *queue, void **item_ref) {
    bool has_item = queue->size;
    if (has_item) {
        *item_ref = queue->buffer[queue->tail];
        --queue->size;
        queue->tail = (queue->tail + 1) % queue->capacity;
    }
    return has_item;
}

bool datadog_php_queue_ctor(datadog_php_queue *queue, uint16_t capacity, void *buffer[static capacity]) {
    if (!queue || (capacity && !buffer)) {
        return false;
    }

    datadog_php_queue q = {
        .size = 0,
        .capacity = capacity,
        .head = 0,
        .tail = 0,
        .buffer = buffer,
    };
    *queue = q;
    return true;
}
