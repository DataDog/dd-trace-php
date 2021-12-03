#ifndef DATADOG_PHP_CHANNEL_H
#define DATADOG_PHP_CHANNEL_H

#include <stdbool.h>
#include <stdint.h>

typedef struct datadog_php_receiver_s datadog_php_receiver;
typedef struct datadog_php_sender_s datadog_php_sender;
typedef struct datadog_php_channel_s datadog_php_channel;
typedef struct datadog_php_channel_impl_s datadog_php_channel_impl;

struct datadog_php_receiver_s {
    /**
     * Receives an item, waiting for a send to the channel if necessary.
     * However, it may return empty-handed and return false, such as if there are
     * no senders left, or the timeout is reached, or if it is interrupted.
     */
    bool (*recv)(datadog_php_receiver *self, void **data, uint64_t timeout_nanos);

    /**
     * Destructs the receiver.
     */
    void (*dtor)(datadog_php_receiver *self);

    // private:
    datadog_php_channel_impl *channel;
};

struct datadog_php_sender_s {
    /**
     * Sends data through the channel. May fail, in which case the caller is
     * responsible for dtor'ing the data if applicable. Does not wait for the
     * data to be received, only enqueued.
     */
    bool (*send)(datadog_php_sender *self, void *data);

    /**
     * Clones the sender. Can fail if there are too many senders already, in
     * which false is returned and the caller should not dtor the clone.
     */
    bool (*clone)(datadog_php_sender *self, datadog_php_sender *clone);

    /**
     * Destructs the sender. Must be done on each successful clone as well.
     */
    void (*dtor)(datadog_php_sender *self);

    // private:
    datadog_php_channel_impl *channel;
};

/**
 * datadog_php_channel is a bounded, multiple-producer, single-consumer channel
 * suitable for inter-thread communication.
 *
 * The user is responsible for any additional cleanup of contents; it does not
 * assume pointers are malloc'd.
 *
 * The channel is not destructed directly. It must outlive any senders and
 * receivers, and the last sender/receiver must dtor the channel when the
 * last sender/receiver is destructed.
 *
 * In the end, I hope this is migrated to Rust, and then we can stop writing
 * our own concurrency primitives and have better lifetime management.
 */
struct datadog_php_channel_s {
    // public:
    datadog_php_receiver receiver;
    datadog_php_sender sender;
};

/**
 * Creates a channel with the sender and receiver members fully initialized.
 * The sender and receiver must be .dtor'd.
 */
bool datadog_php_channel_ctor(datadog_php_channel *channel, uint16_t capacity);

#endif  // DATADOG_PHP_CHANNEL_H
