#include <cstdio>
#include <cstring>

#include "ddwaf.h"

namespace {

// The glibc 2.17 direct-loader regression causes a PIE executable's init
// array to run twice. The scoped non-PIE validation feature must leave this
// at one before any WAF constructor or API work begins.
int startup_constructor_count = 0;
struct startup_constructor_counter {
    startup_constructor_counter() { ++startup_constructor_count; }
} startup_constructor_counter_instance;

bool make_rule(ddwaf_object *ruleset, ddwaf_allocator allocator) {
    constexpr char rule[] = R"json({"version":"2.1","rules":[{"id":"native-smoke-rule","name":"native smoke rule","tags":{"type":"test","category":"native"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"server.request.body"}],"regex":"^native-match$"}}],"on_match":["block"]}]})json";
    return ddwaf_object_from_json(ruleset, rule, sizeof(rule) - 1, allocator);
}

bool make_input(ddwaf_object *input, ddwaf_allocator allocator, const char *body) {
    if (ddwaf_object_set_map(input, 1, allocator) == nullptr) return false;
    ddwaf_object *entry = ddwaf_object_insert_key(input, "server.request.body", 19, allocator);
    if (entry == nullptr || ddwaf_object_set_string(entry, body, std::strlen(body), allocator) == nullptr) return false;
    return true;
}

bool evaluate(ddwaf_context context, ddwaf_object *input, ddwaf_allocator allocator, DDWAF_RET_CODE expected) {
    ddwaf_object result{};
    ddwaf_object_set_invalid(&result);
    const bool matches = ddwaf_context_eval(context, input, allocator, &result, 1000000) == expected;
    ddwaf_object_destroy(&result, allocator);
    return matches;
}

}  // namespace

int main(int argc, char **argv) {
    if (argc != 2 || startup_constructor_count != 1 ||
        std::strcmp(ddwaf_get_version(), "2.0.1") != 0) {
        return 1;
    }

    ddwaf_allocator allocator = ddwaf_synchronized_pool_allocator_init();
    if (allocator == nullptr) {
        return 2;
    }

    ddwaf_object object{};
    constexpr char value[] = "hermetic-libddwaf";
    if (ddwaf_object_set_string(&object, value, sizeof(value) - 1, allocator) == nullptr ||
        !ddwaf_object_is_string(&object)) {
        ddwaf_allocator_destroy(allocator);
        return 3;
    }
    size_t length = 0;
    const char *stored = ddwaf_object_get_string(&object, &length);
    if (stored == nullptr || length != sizeof(value) - 1 ||
        std::memcmp(stored, value, length) != 0) {
        ddwaf_object_destroy(&object, allocator);
        ddwaf_allocator_destroy(allocator);
        return 4;
    }
    ddwaf_object_destroy(&object, allocator);
    ddwaf_allocator_destroy(allocator);

    ddwaf_allocator default_allocator = ddwaf_get_default_allocator();
    ddwaf_object ruleset{};
    if (!make_rule(&ruleset, default_allocator)) return 5;
    ddwaf_handle handle = ddwaf_init(&ruleset, nullptr);
    ddwaf_object_destroy(&ruleset, default_allocator);
    if (handle == nullptr) return 6;
    ddwaf_context context = ddwaf_context_init(handle, default_allocator);
    // A context borrows its WAF instance, so the handle must outlive every
    // context evaluation and its destruction.
    if (context == nullptr) {
        ddwaf_destroy(handle);
        return 7;
    }
    // ddwaf_context_eval retains the data objects and frees their contents
    // when this context is destroyed. Their stack storage must therefore
    // remain alive through ddwaf_context_destroy.
    ddwaf_object nonmatching_input{};
    ddwaf_object matching_input{};
    if (!make_input(&nonmatching_input, default_allocator, "native-miss") ||
        !make_input(&matching_input, default_allocator, "native-match")) {
        ddwaf_context_destroy(context);
        ddwaf_destroy(handle);
        return 7;
    }
    if (!evaluate(context, &nonmatching_input, default_allocator, DDWAF_OK) ||
        !evaluate(context, &matching_input, default_allocator, DDWAF_MATCH)) {
        ddwaf_context_destroy(context);
        ddwaf_destroy(handle);
        return 7;
    }
    ddwaf_context_destroy(context);
    ddwaf_destroy(handle);

    FILE *marker = std::fopen(argv[1], "wb");
    if (marker == nullptr) {
        return 5;
    }
    const int written = std::fprintf(marker, "libddwaf 2.0.1 object-allocation-and-rule-evaluation-ok\n");
    const int closed = std::fclose(marker);
    return closed == 0 && written > 0 ? 0 : 6;
}
