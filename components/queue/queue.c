#include "queue.h"

static bool queue_try_push(struct datadog_php_queue_s *queue, void *item) {
    if (queue->size == queue->capacity) {
        return false;
    }
    ++queue->size;
    queue->buffer[queue->head] = item;
    queue->head = (queue->head + 1) % queue->capacity;
    return true;
}

static bool queue_try_pop(struct datadog_php_queue_s *queue, void **item_ref) {
    bool has_item = queue->size;
    if (has_item) {
        *item_ref = queue->buffer[queue->tail];
        --queue->size;
        queue->tail = (queue->tail + 1) % queue->capacity;
    }
    return has_item;
}

bool datadog_php_queue_ctor(datadog_php_queue *queue, uint16_t capacity, void *buffer[]) {
    if (!queue || (capacity && !buffer)) {
        return false;
    }

    datadog_php_queue q = {
        .size = 0,
        .capacity = capacity,
        .head = 0,
        .tail = 0,
        .buffer = buffer,
        .try_push = queue_try_push,
        .try_pop = queue_try_pop,
    };
    *queue = q;
    return true;
}
