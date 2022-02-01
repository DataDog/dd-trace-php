extern "C" {
#include <components/queue/queue.h>
}

#include <catch2/catch.hpp>

TEST_CASE("empty queue operations", "[queue]") {
    datadog_php_queue queue;
    REQUIRE(datadog_php_queue_ctor(&queue, 0, nullptr));

    void *item = nullptr;
    REQUIRE(!datadog_php_queue_try_push(&queue, item));
    REQUIRE(!datadog_php_queue_try_pop(&queue, &item));
}

TEST_CASE("basic queue operations", "[queue]") {
    datadog_php_queue queue;
    constexpr const unsigned n = 4;
    void *buffer[n];
    REQUIRE(datadog_php_queue_ctor(&queue, n, buffer));

    // we are just pushing in addresses; these values never get dereferenced
    void *item;
    void *items[n];

    // by iterating a few times, we may have wrapped (depending on impl)
    for (unsigned i = 0; i != n; ++i) {
        REQUIRE(datadog_php_queue_try_push(&queue, items + 0));
        REQUIRE(datadog_php_queue_try_push(&queue, items + 1));
        REQUIRE(datadog_php_queue_try_push(&queue, items + 2));
        REQUIRE(datadog_php_queue_try_push(&queue, items + 3));

        REQUIRE(datadog_php_queue_try_pop(&queue, &item));
        REQUIRE(item == items + 0);

        REQUIRE(datadog_php_queue_try_pop(&queue, &item));
        REQUIRE(item == items + 1);

        REQUIRE(datadog_php_queue_try_pop(&queue, &item));
        REQUIRE(item == items + 2);

        REQUIRE(datadog_php_queue_try_pop(&queue, &item));
        REQUIRE(item == items + 3);
    }
}

TEST_CASE("full queue push", "[queue]") {
    datadog_php_queue queue;
    constexpr const unsigned n = 1;
    void *buffer[n];
    REQUIRE(datadog_php_queue_ctor(&queue, n, buffer));

    void *items[n];

    REQUIRE(datadog_php_queue_try_push(&queue, items + 0));
    REQUIRE(!datadog_php_queue_try_push(&queue, items + 0));
}
