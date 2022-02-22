#include "channel.h"

#include <components/queue/queue.h>
#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <uv.h>

struct datadog_php_channel_impl_s {
    datadog_php_queue queue;

    // enforce single consumer (refcount should be 0 or 1)
    uint32_t receiver_count;

    /* Multiple producers are fine. When there aren't any items in the queue, the
     * consumer will use this info to determine whether to try waiting for more
     * data or to return empty handed. If there is a known producer, it will wait;
     * otherwise it will return without waiting.
     */
    uint32_t sender_count;

    uv_mutex_t mutex;
    uv_cond_t condvar;
    void *buffer[];
};

/**
 * Destroys the channel. _NOT_ thread safe.
 */
static void channel_dtor(datadog_php_channel_impl *channel) {
    uv_mutex_destroy(&channel->mutex);
    uv_cond_destroy(&channel->condvar);
    free(channel);
}

static bool receiver_recv(struct datadog_php_receiver_s *receiver, void **data, uint64_t timeout_nanos) {
    if (!receiver || !receiver->channel) {
        return false;
    }

    datadog_php_channel_impl *channel = receiver->channel;
    uv_mutex_lock(&channel->mutex);
    datadog_php_queue *queue = &channel->queue;
    bool succeeded = datadog_php_queue_try_pop(queue, data);

    // If there wasn't an item but there are known producers, wait and try again
    if (!succeeded && channel->sender_count && timeout_nanos) {
        uint64_t now = uv_hrtime();
        uint64_t timeout_target = now + timeout_nanos;
        do {
            /* Handle being signalled, waking up spuriously, and timing out the
             * same. Note that the mutex will be released while waiting, and
             * the mutex will be re-acquired when resuming.
             */
            (void)uv_cond_timedwait(&channel->condvar, &channel->mutex, timeout_target - now);
            succeeded = datadog_php_queue_try_pop(queue, data);
            if (succeeded) {
                break;
            }

            now = uv_hrtime();
        } while (channel->sender_count && now < timeout_target);
    }

    uv_mutex_unlock(&channel->mutex);
    return succeeded;
}

static void receiver_dtor(struct datadog_php_receiver_s *receiver) {
    if (receiver && receiver->channel) {
        datadog_php_channel_impl *channel = receiver->channel;
        receiver->channel = NULL;
        uv_mutex_lock(&channel->mutex);
        // todo: underflow checks
        bool dtor = !(--channel->receiver_count | channel->sender_count);
        uv_mutex_unlock(&channel->mutex);

        // Cannot dtor the mutex while it is held.
        if (dtor) {
            channel_dtor(channel);
        }
    }
}

static bool sender_send(struct datadog_php_sender_s *sender, void *data) {
    if (!sender || !sender->channel) {
        return false;
    }

    datadog_php_channel_impl *channel = sender->channel;
    uv_mutex_lock(&channel->mutex);
    datadog_php_queue *queue = &channel->queue;
    bool sent = datadog_php_queue_try_push(queue, data);
    if (sent) {
        uv_cond_signal(&channel->condvar);
    }
    uv_mutex_unlock(&channel->mutex);
    return sent;
}

static void sender_dtor(struct datadog_php_sender_s *sender) {
    if (sender && sender->channel) {
        datadog_php_channel_impl *channel = sender->channel;
        sender->channel = NULL;
        uv_mutex_lock(&channel->mutex);
        // todo: underflow check
        bool dtor = !(channel->receiver_count | --channel->sender_count);
        uv_mutex_unlock(&channel->mutex);

        // Cannot dtor the mutex while it is held.
        if (dtor) {
            channel_dtor(channel);
        }
    }
}

static bool sender_clone(datadog_php_sender *self, datadog_php_sender *clone) {
    if (!self || !clone || !self->channel) {
        return false;
    }

    datadog_php_channel_impl *channel = self->channel;
    uv_mutex_lock(&channel->mutex);
    // todo: overflow checks
    ++channel->sender_count;
    uv_mutex_unlock(&channel->mutex);

    *clone = *self;

    return true;
}

// not thread safe
static void receiver_ctor(datadog_php_receiver *receiver, datadog_php_channel_impl *channel) {
    receiver->recv = receiver_recv;
    receiver->dtor = receiver_dtor;
    receiver->channel = channel;
}

// not thread safe
static void sender_ctor(datadog_php_sender *sender, datadog_php_channel_impl *channel) {
    sender->send = sender_send;
    sender->clone = sender_clone;
    sender->dtor = sender_dtor;
    sender->channel = channel;
}

bool datadog_php_channel_ctor(datadog_php_channel *channel, uint16_t capacity) {
    size_t bytes = offsetof(datadog_php_channel_impl, buffer) + sizeof(void *) * capacity;
    datadog_php_channel_impl *impl = malloc(bytes);
    if (!impl) {
        return false;
    }

    receiver_ctor(&channel->receiver, impl);
    sender_ctor(&channel->sender, impl);

    if (!datadog_php_queue_ctor(&impl->queue, capacity, impl->buffer)) {
        goto cleanup_impl;
    }

    impl->receiver_count = 1;
    impl->sender_count = 1;

    if (uv_mutex_init(&impl->mutex) != 0) {
        goto cleanup_impl;
    }

    if (uv_cond_init(&impl->condvar) != 0) {
        goto cleanup_mutex;
    }

    return true;

cleanup_mutex:
    uv_mutex_destroy(&impl->mutex);

cleanup_impl:
    free(impl);
    return false;
}
