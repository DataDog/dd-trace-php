#ifndef DATADOG_PHP_QUEUE_H
#define DATADOG_PHP_QUEUE_H

#include <stdbool.h>
#include <stdint.h>

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

    bool (*try_push)(struct datadog_php_queue_s *queue, void *item);
    bool (*try_pop)(struct datadog_php_queue_s *queue, void **item_ref);
} datadog_php_queue;

bool datadog_php_queue_ctor(datadog_php_queue *queue, uint16_t capacity, void *buffer[]);

#endif  // DATADOG_PHP_QUEUE_H
