#ifndef DATADOG_PHP_QUEUE_H
#define DATADOG_PHP_QUEUE_H

#include <stdbool.h>
#include <stdint.h>

#if __cplusplus
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

/**
 * A bounded, NOT thread-safe queue.
 * If you are interested in thread-safety, look at datadog_php_channel.
 *
 * Since this is C, ergonomic, data-type generic queues are beyond us.
 * Instead, we assume the caller will to dtor/free the items in the queue as
 * necessary.
 */
typedef struct datadog_php_queue_s {
    /* We track size instead of computing it from head and tail so that we can
     * use all buffer slots for storage.
     */
    uint16_t size, capacity;
    uint16_t head, tail;
    void **buffer;  // borrowed, not owned
} datadog_php_queue;

bool datadog_php_queue_ctor(datadog_php_queue *queue, uint16_t capacity, void *buffer[C_STATIC(capacity)]);

bool datadog_php_queue_try_pop(datadog_php_queue *queue, void **item_ref);
bool datadog_php_queue_try_push(datadog_php_queue *queue, void *item);

#undef C_STATIC

#endif  // DATADOG_PHP_QUEUE_H
