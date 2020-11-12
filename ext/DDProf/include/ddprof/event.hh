#ifndef DDPROF_EVENT_HH
#define DDPROF_EVENT_HH

#include <sys/types.h>
#include <unistd.h>

#include <cstdint>
#include <type_traits>
#include <utility>
#include <vector>

#include "chrono.hh"

namespace ddprof {

struct basic_event {
    std::size_t name;
    system_clock::time_point timestamp;  // nanoseconds from Unix epoch
};

struct sampled_event {
    basic_event basic;  // must be first member
    std::chrono::nanoseconds sampling_period;
};

struct frame {
    std::size_t function_name;
    std::size_t filename;
    int64_t lineno;
};

struct stack_event {
    sampled_event sampled;  // must be first member
    pid_t thread_id;
    std::size_t thread_name;

    std::vector<frame> frames;

    // todo: span_id, trace_id,
};

// }}}

struct event {
    enum class type : unsigned char {
        basic,
        sampled,
        stack_event,
    } type;

    union {
        basic_event basic;  // all members must have basic as their first member
        sampled_event sampled;
        stack_event stack;
    };

    event(basic_event ev) noexcept;
    event(sampled_event ev) noexcept;
    event(stack_event ev) noexcept;

    event(const event &other) noexcept;
    event(event &&other) noexcept;

    event &operator=(const event &other) noexcept;
    event &operator=(event &&other) noexcept;

    ~event() noexcept;

    template <class Visitor>
    decltype(auto) visit(Visitor visitor) const {
        if (type == type::basic) {
            return visitor(basic);
        } else if (type == type::sampled) {
            return visitor(sampled);
        } else if (type == type::stack_event) {
            return visitor(stack);
        }
    }
};

inline bool operator==(enum event::type lhs, enum event::type rhs) {
    using underlying = std::underlying_type_t<enum event::type>;
    return static_cast<underlying>(lhs) == static_cast<underlying>(rhs);
}

inline event::event(basic_event ev) noexcept : type{type::basic}, basic{ev} {}
inline event::event(sampled_event ev) noexcept : type{type::sampled}, sampled{ev} {}
inline event::event(stack_event ev) noexcept : type{type::stack_event}, stack{std::move(ev)} {}

inline event::event(const event &other) noexcept : type{other.type} {
    if (type == type::basic) {
        basic = other.basic;
    } else if (type == type::sampled) {
        sampled = other.sampled;
    } else if (type == type::stack_event) {
        stack = other.stack;
    }
}

inline event::event(event &&other) noexcept : type{other.type} {
    if (type == type::basic) {
        basic = other.basic;
    } else if (type == type::sampled) {
        sampled = other.sampled;
    } else if (type == type::stack_event) {
        stack = std::move(other.stack);
    }
}

inline event::~event() noexcept {
    if (type == type::basic) {
        basic.~basic_event();
    } else if (type == type::sampled) {
        sampled.~sampled_event();
    } else if (type == type::stack_event) {
        stack.~stack_event();
    }
}

}  // namespace ddprof

#include <iostream>

// ostream overloads for debugging; may be removed in future
namespace ddprof {

inline std::ostream &operator<<(std::ostream &out, enum event::type type) {
    using underlying = std::underlying_type_t<enum event::type>;
    return out << (int)static_cast<underlying>(type);
}

inline std::ostream &operator<<(std::ostream &out, const struct event &event) {
    out << "{ type:" << event.type;
    out << "; name: " << event.basic.name;
    out << "; timestamp: " << event.basic.timestamp.time_since_epoch().count();

    if (event.type == event::type::sampled || event.type == event::type::stack_event) {
        // todo: more info on other event types
    }

    return out << " }";
}

}  // namespace ddprof

#endif  // DDPROF_EVENT_HH
