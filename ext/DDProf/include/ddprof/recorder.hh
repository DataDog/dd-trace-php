#ifndef DDPROF_RECORDER_HH
#define DDPROF_RECORDER_HH

#include <cstdint>
#include <memory>
#include <mutex>
#include <type_traits>
#include <unordered_map>
#include <utility>
#include <vector>

#include "event.hh"
#include "string_table.hh"

namespace ddprof {

// TODO: set limits on maximum number of events
class recorder {
    public:
    struct event_hash {
        std::size_t operator()(enum event::type type) const noexcept;
    };
    using event_table_t = std::unordered_map<enum event::type, std::vector<std::unique_ptr<event>>, event_hash>;

    private:
    std::mutex m{};
    event_table_t event_table{};
    string_table strings{};

    public:
    // All public operations on object must be thread-safe with each other

    void push(std::unique_ptr<event> event);
    void push(size_t num_events, std::unique_ptr<event> events[]);

    interned_string &intern(hashed_string);
    interned_string &intern(interned_string &);

    std::pair<event_table_t, string_table> release() noexcept;
};

inline void recorder::push(std::unique_ptr<event> event) {
    std::lock_guard<std::mutex> lock{m};
    event_table[event->type].emplace_back(std::move(event));
}

inline void recorder::push(size_t num_events, std::unique_ptr<event> events[]) {
    std::lock_guard<std::mutex> lock{m};
    for (size_t i = 0; i < num_events; ++i) {
        auto event = events[i].release();
        event_table[event->type].emplace_back(event);
    }
}

inline std::pair<recorder::event_table_t, string_table> recorder::release() noexcept {
    recorder::event_table_t events_tmp{};
    string_table strings_tmp{};
    {
        std::lock_guard<std::mutex> lock{m};
        events_tmp.swap(event_table);
        strings_tmp.swap(strings);
    }

    return std::make_pair(std::move(events_tmp), std::move(strings_tmp));
}

inline std::size_t recorder::event_hash::operator()(enum event::type type) const noexcept {
    using underlying = std::underlying_type<enum event::type>::type;
    return std::hash<underlying>{}(static_cast<underlying>(type));
}

inline interned_string &recorder::intern(hashed_string string) {
    std::lock_guard<std::mutex> lock{m};
    return strings.intern(string);
}

inline interned_string &recorder::intern(interned_string &string) {
    std::lock_guard<std::mutex> lock{m};
    return strings.intern(string);
}

}  // namespace ddprof

#endif  // DDPROF_RECORDER_HH
