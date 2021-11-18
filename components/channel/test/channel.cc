extern "C" {
#include <channel/channel.h>
}

#include <uv.h>

#include <catch2/catch.hpp>

static void print_assert(const char *expr, const char *file, int line) {
    fprintf(stderr, "assertion \"%s\" failed: in file \"%s\", on line %d\n", expr, file, line);
}

// avoid UINT64_MAX due to likely math overflow logic errors
static const uint64_t TIMEOUT = UINT64_C(10000000000);

// Catch2's macros are not thread-safe, so return a bool for status
struct args {
    bool succeeded;
    datadog_php_sender *sender;
    datadog_php_receiver *receiver;
};

static void basic_receiver_job(args *args) {
    datadog_php_receiver *receiver = args->receiver;
    int *item;
    // clang-format off
#define ASSERT(EXPR) (void)((EXPR) || (print_assert(#EXPR, __FILE__, __LINE__), args->succeeded = false))
    // clang-format on
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 1);
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 2);
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 3);
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 4);
#undef ASSERT
}

TEST_CASE("basic channel operations", "[channel]") {
    uv_thread_t receiver_thread;

    constexpr const uint16_t n = 4;
    int values[n] = {1, 2, 3, 4};

    datadog_php_channel channel;
    REQUIRE(datadog_php_channel_ctor(&channel, n));

    datadog_php_receiver *receiver = &channel.receiver;
    datadog_php_sender *sender = &channel.sender;

    args args = {
        true,
        nullptr,
        receiver,
    };
    REQUIRE(uv_thread_create(&receiver_thread, reinterpret_cast<uv_thread_cb>(basic_receiver_job), &args) == 0);

    REQUIRE(sender->send(sender, &values[0]));
    REQUIRE(sender->send(sender, &values[1]));
    REQUIRE(sender->send(sender, &values[2]));
    REQUIRE(sender->send(sender, &values[3]));

    REQUIRE(uv_thread_join(&receiver_thread) == 0);

    REQUIRE(args.succeeded);

    receiver->dtor(receiver);
    sender->dtor(sender);
}

static void stream_channel_ops(args *args) {
    datadog_php_receiver *receiver = args->receiver;
    datadog_php_sender *sender = args->sender;
    int *item;

    // clang-format off
#define ASSERT(EXPR) (void)((EXPR) || (print_assert(#EXPR, __FILE__, __LINE__), args->succeeded = false))
    // clang-format on

    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 1);
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 2);

    // notify the other channel we've recv'd the first two items
    ASSERT(sender->send(sender, nullptr));

    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 2);
    ASSERT(receiver->recv(receiver, (void **)&item, TIMEOUT));
    ASSERT(*item == 1);
#undef ASSERT
}

TEST_CASE("stream channel operations", "[channel]") {
    uv_thread_t receiver_thread;

    constexpr const uint16_t n = 2;
    int values[n] = {1, 2};

    datadog_php_channel A, B;
    REQUIRE(datadog_php_channel_ctor(&A, n));
    REQUIRE(datadog_php_channel_ctor(&B, n));

    args ops = {
        true,
        &B.sender,
        &A.receiver,
    };
    REQUIRE(uv_thread_create(&receiver_thread, reinterpret_cast<uv_thread_cb>(stream_channel_ops), &ops) == 0);
    REQUIRE(A.sender.send(&A.sender, &values[0]));
    REQUIRE(A.sender.send(&A.sender, &values[1]));

    /* Since the buffer is only 2 in size, we need to make sure the other values
     * have been recv'd before sending new ones, or new ones will fail to send.
     * This is the purpose of the second channel.
     */
    void *item;
    REQUIRE(B.receiver.recv(&B.receiver, &item, TIMEOUT));

    // The previous sends have been recv'd; we are clear to send new values.
    A.sender.send(&A.sender, &values[1]);
    A.sender.send(&A.sender, &values[0]);

    REQUIRE(uv_thread_join(&receiver_thread) == 0);

    REQUIRE(ops.succeeded);

    A.receiver.dtor(&A.receiver);
    A.sender.dtor(&A.sender);
    B.receiver.dtor(&B.receiver);
    B.sender.dtor(&B.sender);
}

TEST_CASE("channel empty recv does not wait if no producers", "[channel]") {
    constexpr const uint16_t n = 1;
    datadog_php_channel channel;
    REQUIRE(datadog_php_channel_ctor(&channel, n));

    // close the sender so when the receiver recvs there aren't any senders
    channel.sender.dtor(&channel.sender);

    void *item;
    REQUIRE(!channel.receiver.recv(&channel.receiver, &item, TIMEOUT));
    // todo: if this doesn't pass, it will likely run until the caller kills it

    channel.receiver.dtor(&channel.receiver);
}
